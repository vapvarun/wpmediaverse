# WPMediaVerse Master Plan

> **Status: ROADMAP. Part 2 onwards is NOT BUILT.** Part 1 describes shipped 2.4.0
> behaviour; everything under "Next 6 months" is intent, not code, and the version targets
> in it predate 2.4.0. Nothing in Part 2 may be cited as an existing feature. Completed
> work and historical context belong in git log / changelogs, not here. Refresh every
> release cycle.

**Baseline:** Free 2.4.0 + Pro 2.4.0 (paired).
**Horizon:** next 6 months.
**Owner:** Varun.

---

## Part 1 — What WPMediaVerse is today

### Free — core platform
- Custom-table media storage. 23 `mvs_*` tables, no `wp_posts` dependency for media records.
- 6 privacy levels: public, members, friends, private, group, custom (access rules).
- Storage drivers: local. Cloud drivers (BunnyCDN, AWS S3, Cloudflare R2, DigitalOcean Spaces) shipped via Pro.
- Image variant pipeline: large/medium/thumb generated at upload, with WebP + AVIF siblings.
- Video poster generation: getID3 cover-atom → bundled default poster SVG.
- Audio cover/waveform: ID3 art extraction → render-time SHA-1-seeded SVG waveform.
- Signed-URL `/serve` endpoint with HMAC + expiry + per-request privacy re-check.
- Direct-CDN bypass for public media on cloud drivers (filter `mvs_public_cloud_thumbnail_url`).
- REST API: 115 endpoints across 25 controllers (`mvs/v1`), plus 86 in Pro (`mvs-pro/v1`).
- Gutenberg blocks: 9 registered in Free (13 `block.json` dirs; 4 Interactivity-only) + 15 Pro.
- BuddyPress integration: activity composer, profile tabs, group tabs, notifications, member photos.
- Albums (custom post type) + collections (smart auto-curation).
- Social layer: reactions (6 types), threaded comments, favorites, follows, @mentions, sharing.
- Direct messaging (text + media) — no third-party service.
- AI moderation: OpenAI Vision (Free), Google Vision + AWS Rekognition (Pro). Budget cap + per-feature toggles.
- Activity feed + notifications.
- Lightbox: full-viewport, picture-element WebP/AVIF, Interactivity API.
- Image optimization: lossless re-encode + per-variant filter `mvs_optimize_image` for external compressors.
- WP-CLI: `wp mvs optimize`, `migrate-storage`, `cloud-thumbs-backfill`, `relocalize-private`, `cleanup-local`.

### Pro — extensions
- Competitions: 1v1 photo battles, weekly photo challenges, single-elimination tournaments, boosts (gamification credits), daily upload streaks.
- Cloud drivers: BunnyCDN, AWS S3 (SigV4), Cloudflare R2, DigitalOcean Spaces. Test Connection per driver. Constant-backed credentials.
- Storage Management screen: per-service file/size counts, one-click Migrate all (chunked AJAX + progress bar), batched Move/Delete-next-N.
- Video: chapters, resume playback, auto-captions (Whisper provider).
- Video heatmaps + play analytics.
- Watermarking: text (TTF + GD), image/logo, tile, position presets, dynamic tokens (`{username}`, `{site}`). Imagick-safe via GD-forcing filter.
- Quota management: per-user upload + storage limits. Adapters for MemberPress, PaidMembershipsPro, WooCommerce Memberships.
- Migrators: rtMedia, MediaPress, BuddyBoss media → WPMediaVerse (WP-CLI + admin UI).
- Connector framework: Flickr (OAuth 1.0a). Extension points for more platforms.
- Feed layouts: Instagram, Flickr, Pinterest, Dribbble — for the 4 platform-feed blocks.
- Privacy UI: advanced access-rules editor.
- Documents (2.4.0): user/space/group drives, folder tree, text extraction + full-text search, tiered previews, per-drive permissions. The only Pro surface whose WRITES are licence-gated (`Documents\DocumentLicense`); reads and registration never are.
- License + EDD Software Licensing — **updates only**, not a feature gate (see Documents above for the single exception).

### Cross-cutting infrastructure
- Single read-side facade: `Core\MediaUrl` (`thumb()`, `file()`).
- Single upload write-side: `VariantSpec` + `StorageRouter` + `MediaVariantWriter` + `PosterService`.
- Settings contract test + register_setting whitelist enforcement.
- Local-CI gate: PHP lint + WPCS + PHPStan + coding rules + UX audit + wppqa baseline + journeys.
- Manifest-driven audit dashboard at `audit/graph.html`.
- Customer journeys (regression-test specs) under `audit/journeys/customer/`.

---

## Part 2 — Next 6 months

### Committed (cards exist, scope written)

| Target | Item | Tracking |
|---|---|---|
| 1.6.0 | **Phase 4 — fold `MediaController::replace_file()` into the unified upload pipeline.** Adds EXIF strip + variant gen + WebP/AVIF + poster to replace flow. Gated behind filter `mvs_replace_uses_unified_pipeline` (default true). | Basecamp 9936426283 |
| 1.6.0 | **TikTok-style dynamic per-user watermark overlay.** Token substitution exists (`{username}`, `{site}`); needs UI for logo + per-user image overlay and combined logo+text composite. | follow-up to 9919544241 |
| 1.6.0 | **Server-side first-frame poster extraction.** Customer ask on cover-less videos: render the actual frame, not the default SVG. Needs `wp_read_video_metadata()` at upload time + writing back to `thumb_*`. | follow-up to 9910574354 |
| 1.6.0 | **Stale-branch hygiene.** Branch-deletion step baked into PR merge so we never accumulate orphan `fix/*` branches again. Use one version-branch per release cycle. | workflow rule |
| 1.6.0 | **Customer recovery CLI polish.** `wp mvs cloud-thumbs-backfill` already exists but isn't well-known. Doc + admin UI nudge for sites that hit pre-1.5.0 BunnyCDN poster bug. | follow-up to 9882148131 |
| 1.6.0 | **Align Explore + profile media tab audience filters.** Currently `MediaController::get_items` (Explore) shows only `public + members + own` to logged-in viewers, hiding `friends` / `group` / `custom` even from members of those audiences. `ProfileTabIntegration` (profile media tab) is more permissive — shows all-except-private. A user who's friends with admin can find admin's friends-only items by visiting admin's profile but NOT by browsing Explore. Privacy is honored at `/serve` either way; this is a discovery UX inconsistency. Unify on a shared audience-aware WHERE so a friend can discover friends-only items everywhere they're entitled to. | 1.5.0 audit finding |
| 1.9.0 | **Explore URL unification — single source of truth, remove hardcoded `/media/`.** The search form action is hardcoded `home_url('/media/')` (explore.php:21) and the literal repeats in ~10 sites (album/media-single/404/cpt-archive templates, Shortcodes, OverviewPage, MediaRepository single permalinks, TemplateHelpers fallback), bypassing the page-mapping-aware `resolve_explore_url()` we already have. Two explore surfaces diverge: `/media/` (rewrite base — handles `?s=`/`?mvs_tag=`/pagination/`@user`/`/{slug}/`) vs `/explore-media/` (the `mvs_page_explore` page — 404s on `?s=`). Fix: one public `MediaUrl::explore()` helper used everywhere + reconcile the page-vs-rewrite split (redirect `/explore-media/`→`/media/`, or teach the page to handle the query vars). NOT a 1.8.0 regression — search works today via the hardcoded `/media/`; this is consistency/maintainability debt found in the 1.8.0 Reign+BuddyX smoke. | Basecamp 10024102379 |

### Code-organization debt (file when next touched)

These don't ship as features but are tracked so the next person editing the area knows to extract:

- `Services/UploadService.php` — extract `ValidatorService` + `ProgressTrackerService` (already PARTIAL in Known Debt).
- `Admin/Settings/SettingsRegistrar.php` — split remaining settings groups using the `AiSettingsRegistrar` template.
- Pro `Analytics/AnalyticsService.php` — extract `AnalyticsIngestor`.
- Pro `Tournaments/TournamentService.php` — extract `TournamentRepository`.
- Pro `Challenges/ChallengeService.php` — extract scoring vs state-machine.

### Likely (high signal but unscheduled)

| Theme | Item |
|---|---|
| AI | **AI alt-text generation** for accessibility. Reuse the existing `AIProviderInterface` (OpenAI Vision, Google, Rekognition). |
| AI | **AI captions on image upload** (similar to existing video Whisper captions). |
| Connectors | **Instagram personal-feed connector** (Pro Connectors framework already supports Flickr; add Instagram). |
| Storage | **Backblaze B2 driver** (S3-compatible — likely a 1-day extension of existing S3 driver). |
| Storage | **Cleanup-local guardrail UI**: confirm dialog + dry-run preview before customer deletes local copies that haven't been migrated yet. |
| Mobile | **Mobile responsiveness sweep** of admin pages. Frontend is already 390px-clean; admin not audited. |
| Multilingual | **Polylang / WPML compatibility audit.** Currently i18n-clean but multilingual-plugin behavior isn't tested. |

### Strategic / exploratory (no commitment)

| Theme | Item |
|---|---|
| Architecture | **GraphQL read endpoint** for headless WordPress integrations. WPGraphQL extension. |
| Performance | **Per-media object cache layer** in `MediaRepository`. Has request-cache + prefetch already; persistent cache would help heavy-read sites. |
| Compat | **BuddyBoss Platform deeper integration** — beyond the existing importer. Activity types, member directory hooks. |
| Compat | **Multisite network-admin shared media library.** |
| Pro | **Bunny Stream video encoding** — multi-quality renditions via a cloud encoder, replacing the FFmpeg path removed in 2.4.0. DESIGNED, NOT BUILT: `docs/architecture/specs/2026-08-30-bunny-stream-video-encoding.md`. Blocked on four owner decisions in its §12 — read those first, the plan is not startable without them. |
| Pro | **Live-stream support** (RTMP ingest + HLS playback). Major scope. Would share the Bunny Stream integration above if that lands first. |
| Pro | **Marketplace mode** — monetize media (already have credits SDK and quota; missing storefront UI). |

---

## How to refresh this plan

- After each release: trim the "Committed" rows that shipped, promote items from "Likely" to "Committed" if scoped, move "Strategic" items down or out.
- Don't add "completed" sections — that's what `readme.txt` changelogs and `git log` are for.
- One owner change: update the header. One scope change: edit in place, don't fork.

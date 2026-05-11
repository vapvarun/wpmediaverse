# WPMediaVerse Pro — Feature Audit

**Generated:** 2026-05-03 · **Plugin version:** 1.2.0 · **Branch:** `1.2.0` · **Extends:** `wpmediaverse` (Free)

**Counts at a glance:** 37 REST routes (`mvs-pro/v1`) · 6 AJAX handlers · 10 admin pages · 7 feature toggles · **12 Gutenberg blocks** · **12 shortcodes** · 19 sub-systems · 8 Pro-only tables · 4 cron events · 2 cloud storage drivers · 3 WP-CLI importers · 16 frontend templates · 12 coding rules.

> Pro hooks into Free via `mvs_loaded` and never imports Free classes directly (sole exception: `MediaRepository`, with a Phase 1 interface contract pending full retirement). Read `manifest.json` for the machine-readable inventory; this doc is the human-readable view.

Companion docs:
- [`manifest.json`](manifest.json) — machine-readable canonical inventory
- [`manifest.summary.json`](manifest.summary.json) — ≤2 KB index
- [`CODE_FLOWS.md`](CODE_FLOWS.md) — request lifecycle for the major Pro features
- [`ROLE_MATRIX.md`](ROLE_MATRIX.md) — capability vs. feature grid
- [`derived/`](derived/) — sub-check caches (cross-plugin coupling, registry strategy, suppressed baseline, etc.)
- [`wppqa-baseline-2026-05-03/SUMMARY.md`](wppqa-baseline-2026-05-03/SUMMARY.md) — bug-finder baseline (0 real bugs)
- [`../../wpmediaverse/audit/manifest.json`](../../../wpmediaverse/audit/manifest.json) — Free's manifest (for cross-plugin reference)

---

## 1. REST API (`mvs-pro/v1`)

### 1.1 Connectors (Flickr, OAuth platforms)

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/connectors` | GET | `ConnectorRESTController::list_connectors` | logged-in |
| `/connectors/{id}/connect` | POST | `ConnectorRESTController::start_connect` | logged-in |
| `/connectors/{id}/disconnect` | POST | `ConnectorRESTController::do_disconnect` | connected |
| `/connectors/{id}/status` | GET | `ConnectorRESTController::get_status` | connected |
| `/connectors/{id}/browse` | GET | `ConnectorRESTController::browse_remote` | connected |
| `/connectors/{id}/import` | POST | `ConnectorRESTController::import_media` | connected |
| `/connectors/{id}/sync-delta` | POST | `ConnectorRESTController::sync_delta` | connected |

### 1.2 Battles (1v1 photo battles)

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/battles` | GET / POST | `BattleController::*` | public read; logged-in create |
| `/battles/{id}` | GET | `BattleController::get_item` | public |
| `/battles/{id}/accept` | POST | `BattleController::accept_item` | opponent |
| `/battles/{id}/decline` | POST | `BattleController::decline_item` | opponent |
| `/battles/{id}/submit` | POST | `BattleController::submit_entry` | participant |
| `/battles/{id}/vote` | POST | `BattleController::vote_item` | can_vote |

### 1.3 Challenges (themed weekly photo challenges)

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/challenges` | GET / POST | `ChallengeController::*` | public read; admin create |
| `/challenges/{id}` | GET | `ChallengeController::get_item` | public |
| `/challenges/{id}/cancel` | POST | `ChallengeController::cancel_item` | creator/admin |
| `/challenges/{id}/entries` | GET | `ChallengeController::get_entries` | public |
| `/challenges/{id}/entries/{entry_id}/vote` | POST | `ChallengeController::vote_entry` | logged-in |
| `/challenges/{id}/results` | GET | `ChallengeController::get_results` | public |

### 1.4 Tournaments (single-elim brackets)

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/tournaments` | GET / POST | `TournamentController::*` | public read; admin create |
| `/tournaments/{id}` | GET | `TournamentController::get_item` | public |
| `/tournaments/{id}/bracket` | GET | `TournamentController::get_bracket` | public |
| `/tournaments/{id}/register` | POST | `TournamentController::register_item` | logged-in |
| `/tournaments/{id}/matches/{match_id}/vote` | POST | `TournamentController::vote_match` | logged-in |

### 1.5 Quota + Boosts

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/me/quota` | GET | `QuotaController::get_my_quota` | logged-in |
| `/users/{id}/quota` | GET | `QuotaController::get_user_quota` | admin |
| `/users/{id}/quota/assign` | POST | `QuotaController::assign_package` | admin |
| `/me/boosts` | GET | `BoostController::get_my_boosts` | logged-in |
| `/boosts` | POST | `BoostController::create_boost` | logged-in |

### 1.6 Video + Captions + Analytics

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/captions/{media_id}` | GET / POST | `CaptionController::*` | media owner / admin |
| `/transcode` | POST | `TranscodeController::start_transcode` | admin |
| `/transcode/{job_id}` | GET | `TranscodeController::get_status` | admin |
| `/videos/{id}/chapters` | GET | `VideoController::get_chapters` | public |
| `/videos/{id}/resume` | GET / POST | `VideoController::*` | viewer |
| `/videos/{id}/analytics` | GET | `AnalyticsController::get_heatmap` | owner / admin |

### 1.7 Privacy + Compete summary

| Route | Method | Handler | Permission |
|---|---|---|---|
| `/privacy/settings` | GET / POST | `PrivacyController::*` | logged-in |
| `/compete-summary` | GET | `CompeteSummaryController::get_summary` | logged-in |

---

## 2. AJAX Handlers (admin-only — Rule 5 allowlist)

The 6 handlers below are the documented `wp_ajax_*` allowlist enforced by `bin/coding-rules-check.sh` Rule 5 (added 2026-05-02, Phase 6). Off-list registrations fail the gate; new functionality must use a REST route.

| Action | Handler | Nonce | Capability |
|---|---|---|---|
| `mvs_pro_test_s3` | `Core/ConnectionTester::test_s3` | `mvs_test_storage_nonce` | `manage_options` |
| `mvs_pro_test_bunny` | `Core/ConnectionTester::test_bunny` | `mvs_test_storage_nonce` | `manage_options` |
| `mvs_save_connector_prefs` | `Admin/ProSettings::ajax_save_connector_prefs` | `mvs_nonce` | `manage_options` |
| `mvs_dismiss_gamification_welcome` | `Admin/CompetitionsDashboard::ajax_dismiss_gamification_welcome` | `mvs_nonce` | `manage_options` |
| `mvs_migration_batch` | `Admin/MigrationPage::ajax_run_batch` (dispatches to per-platform `MigrationAdmin::run_batch`) | `mvs_migration_nonce` | `manage_options` |
| `mvs_migration_reset` | `Admin/MigrationPage::ajax_reset_state` | `mvs_migration_nonce` | `manage_options` |

> `mvs_migration_detect` (in the previous audit) was retired during Phase 5 P2.1 — detection is now part of each `MigrationAdmin::is_available()`/`count_total()` rendered server-side at page load.

---

## 3. Admin Pages

Pro adds a top-level **Competitions** menu plus 4 sub-pages under Free's `wpmediaverse` menu.

| Page | Slug | Parent | Source |
|---|---|---|---|
| Competitions | `mvs-competitions` | (top-level) | `Admin/CompetitionsDashboard.php` |
| Challenges | `mvs-challenges` | `mvs-competitions` | `Admin/ChallengeManager.php` |
| Battles | `mvs-battles` | `mvs-competitions` | `Admin/BattleMonitor.php` |
| Tournaments | `mvs-tournaments` | `mvs-competitions` | `Admin/TournamentManager.php` |
| Analytics | `mvs-analytics` | `mvs-competitions` | `Admin/AnalyticsDashboard.php` |
| Reports | `mvs-reports` | `mvs-competitions` | `Admin/ReportManager.php` |
| Pro Settings | `mvs-pro-settings` | `wpmediaverse` (Free) | `Admin/ProSettings.php` |
| Quota | `mvs-quota` | `wpmediaverse` | `Admin/QuotaPage.php` |
| Theme Library | `mvs-theme-library` | `wpmediaverse` | `Admin/ThemeLibrary.php` |
| Migration Tools | `mvs-migration` | `wpmediaverse` | `Admin/MigrationPage.php` (627-line shell hosting per-platform `Integrations\<Platform>\MigrationAdmin` cards — see Section 14) |

---

## 4. Feature Toggles

Each Pro feature is gated by a `get_option('mvs_*_enabled')` check in `Core/Plugin::init()`. When disabled, the service is `null` and all dependent routes/admin pages skip.

| Option | Default | Controls |
|---|---|---|
| `mvs_battles_enabled` | `0` | 1v1 photo battles UI + service + admin monitor |
| `mvs_challenges_enabled` | `0` | Themed weekly challenges + autopilot weekly cron + admin manager |
| `mvs_tournaments_enabled` | `0` | Tournament brackets + service + controller + admin manager |
| `mvs_boosts_enabled` | `0` | Visibility boosts via gamification points |
| `mvs_streaks_enabled` | `0` | Streak badge UI (StreakService loads regardless; toggle gates UI only) |
| `mvs_connectors_enabled` | `0` | Platform connectors (Flickr, etc.) |
| `mvs_pro_transcode_enabled` | `0` | Video transcoding pipeline via FFmpeg |

---

## 5. Sub-systems

19 namespaces under `WPMediaVersePro\`. Each is feature-toggle-gated where applicable. After 1.2.0 Phase 2, every platform-coupled file lives under `includes/Integrations/<Platform>/`; capability-named subdirs (`Storage/`, `AI/`, `Quota/Adapters/`, `Connectors/Flickr/`) no longer exist.

| Namespace | Key classes | Purpose |
|---|---|---|
| `Battles` | `BattleService`, `BattleController`, `Renderer` | 1v1 battles with matching, voting, winner selection (`Renderer` = string-returning Phase 3b renderer for blocks/shortcodes) |
| `Challenges` | `ChallengeService`, `ChallengeController`, `AutopilotService`, `ChallengeNotificationListener`, `Renderer` | Weekly challenges with entry deadlines, voting windows, weekly cron (`Renderer` Phase 3b) |
| `Tournaments` | `TournamentService`, `TournamentController`, `TournamentNotificationListener`, `Renderer` | Single-elimination brackets, round orchestration, seeding (`Renderer` Phase 3b) |
| `Boosts` | `BoostService`, `BoostController` | Spend points to boost media impressions |
| `Streaks` | `StreakService` | Daily upload streaks + streak badges |
| `Video` | `VideoController`, `ChapterService`, `ResumeService`, `TranscodeService`, `TranscodeController` | Chapters, resume position, FFmpeg transcoding queue |
| `Captions` | `CaptionController`, `TranscriptionService` | Auto-captions orchestration (Whisper provider lives in `Integrations/Whisper/`) |
| `Analytics` | `AnalyticsService`, `AnalyticsController` | Video heatmaps, play analytics, daily aggregation |
| `Quota` | `QuotaService`, `QuotaController` | Upload/storage quotas (membership adapters live in `Integrations/<Platform>/QuotaAdapter`) |
| `AI` | `CircuitBreaker` (trait) | Vendor-neutral AI infrastructure (vendor providers live in `Integrations/<Platform>/AIProvider`) |
| `Connectors` | `ConnectorInterface`, `ConnectorManager`, `ConnectorRESTController`, `OAuthHelper` | Vendor-neutral connector framework (per-platform connectors live in `Integrations/Flickr/`) |
| `CLI` | `ImportThumbnailTrait` | Vendor-neutral CLI trait (per-platform importers live in `Integrations/<Platform>/Importer`) |
| `Privacy` | `PrivacyUIService`, `PrivacyController` | Advanced privacy controls UI |
| `Frontend` | `UsageWidget`, `InstagramFeedService`, `LayoutManager`, `GamificationTemplateLoader`, `InstagramLayout`, `PinterestLayout`, `FlickrLayout`, `DribbbleLayout`, `LayoutMode` (interface), `LeaderboardRenderer`, `CompeteHubRenderer` | Frontend layouts (Phase 3a `render_feed(array $args = []): string` contract on `LayoutMode`); Phase 3b string renderers for leaderboard + compete-hub |
| `Blocks` | `BlockRegistrar`, `Shortcodes`, `StandardAttributes`, `MVS_CSS` | 1.2.0 Phase 3c–3e: block registrar, shortcode registrar, standard-attribute injection (20 layout/spacing/border/shadow/visibility attrs), per-instance scoped CSS (keyed off `mvs-block-{uniqueId}`, dumped on `wp_footer`) |
| `Integrations` | `AbstractMigrationAdmin` (Phase 5 P2.1), `AbstractBatchImporter` (Phase 5 P2.2), `Flickr\Connector`, `Flickr\Client`, `Flickr\Mapper`, `AmazonS3\StorageDriver`, `BunnyCDN\StorageDriver`, `GoogleVision\AIProvider`, `Rekognition\AIProvider`, `Whisper\CaptionProvider`, `MemberPress\QuotaAdapter`, `PaidMembershipsPro\QuotaAdapter`, `WooCommerce\QuotaAdapter`, `RtMedia\{Importer,MigrationAdmin}`, `MediaPress\{Importer,MigrationAdmin}`, `BuddyBoss\{Importer,MigrationAdmin}` | One subdir per external platform; class names role-named, never vendor-prefixed (`StorageDriver`, not `S3StorageDriver`) |
| `Core` | `Plugin`, `LicenseManager`, `Migrator`, `ConnectionTester`, `Watermarker` | Bootstrap, container bridge, table migrator, AJAX connection testers, watermark generator |
| `Admin` | `ProSettings`, `QuotaPage`, `MigrationPage`, `ReportManager`, `AnalyticsDashboard`, `GamificationSettings`, `ChallengeManager`, `TournamentManager`, `BattleMonitor`, `CompetitionsDashboard`, `ThemeLibrary` | All Pro admin pages (`MigrationPage` is the 627-line shell hosting per-platform cards) |
| `License` | `License` | EDD license key UI |

---

## 6. Custom Database Tables (8 Pro-only)

All prefixed `{$wpdb->prefix}mvs_`. Schema in `Core/Migrator.php`.

| Table | Purpose |
|---|---|
| `mvs_quota_packages` | Quota plan definitions (image/video/audio/storage limits) |
| `mvs_credit_log` | Gamification points transaction history |
| `mvs_play_events` | Video analytics events (play/pause/seek/complete) |
| `mvs_competitions` | Unified competition header (battles/challenges/tournaments) |
| `mvs_competition_entries` | Participants/submissions |
| `mvs_competition_matches` | Head-to-head matches (battles + tournament rounds) |
| `mvs_competition_votes` | Votes on entries or matches |
| `mvs_boosts` | Media visibility boosts (points spent) |

---

## 7. Frontend Templates (Interactivity API)

| Template | Renders | Interactivity NS |
|---|---|---|
| `templates/battles.php` | Battle arena with live voting | `mvs-pro/battles` |
| `templates/challenges.php` | Challenge list + detail (cover-image fallback as of 1.1.3) | `mvs-pro/challenges` |
| `templates/tournaments.php` | Bracket visualizer + match voting | `mvs-pro/tournaments` |
| `templates/compete-hub.php` | Dashboard with all competitions | (composite) |
| `templates/instagram-feed.php` | Instagram-style feed | `mvs-pro/instagram-feed` |
| `templates/user-profile.php` | User media profile card | (static) |
| `templates/layouts/instagram/feed.php` | Instagram feed grid (3-col) | (composite) |
| `templates/layouts/instagram/profile.php` | Instagram profile | (composite) |
| `templates/layouts/pinterest/feed.php` | Pinterest masonry | (composite) |
| `templates/layouts/flickr/feed.php` | Flickr-style gallery | (composite) |
| `templates/layouts/dribbble/feed.php` | Dribbble shot grid | (composite) |
| `templates/partials/feed-card.php` | Feed card (signed via MediaUrl::for_file as of 1.1.3) | (partial) |
| `templates/layouts/instagram/partials/feed-card.php` | Instagram feed card (signed) | (partial) |
| `templates/partials/boost-modal.php` | Boost points spend modal | (modal) |
| `templates/partials/streak-widget.php` | Daily streak badge | (widget) |
| `templates/partials/stories-bar.php` | Stories carousel | (composite) |

---

## 8. Cron Hooks

| Hook | Interval | Handler | Purpose |
|---|---|---|---|
| `mvs_pro_transcode_cleanup` | hourly | `Video/TranscodeService::cleanup_old_jobs` | Drop completed/failed transcode jobs > 7 days |
| `mvs_pro_challenges_autopilot_weekly` | weekly | `Challenges/AutopilotService::run_weekly` | Auto-create challenges, roll voting windows, crown winners |
| `mvs_pro_streaks_daily_reset` | daily | `Streaks/StreakService::reset_daily` | Reset upload eligibility for next day's streak check |
| `mvs_pro_prune_play_events` | daily | `Analytics/AnalyticsService::prune_old_events` | Drop play events > 90 days |

---

## 9. Storage Drivers

| Driver | Class | Config |
|---|---|---|
| S3 | `Integrations/AmazonS3/StorageDriver.php` | `mvs_pro_s3_bucket`, `_region`, `_access_key`, `_secret_key`, `_cdn_domain` (or env constants `MVS_PRO_AWS_*`) |
| BunnyCDN | `Integrations/BunnyCDN/StorageDriver.php` | `mvs_pro_bunny_storage_zone`, `_access_key`, `_pull_zone` |

Pro registers via Free's `mvs_storage_driver` filter. Both drivers implement `WPMediaVerse\Services\StorageDriverInterface` (`store(source, dest)`, `retrieve(dest, local)`, `delete(dest)`, `url(dest)`).

---

## 10. WP-CLI Importers

All three extend `WPMediaVersePro\Integrations\AbstractBatchImporter` (Phase 5 P2.2 base) which owns flag parsing, the batched fetch/import/progress loop, dedup against `mvs_media_meta`, and the final summary line. Subclasses supply only platform-specific bits — environment probe, source query, dedup meta key, per-row mapping. They also share `WPMediaVersePro\CLI\ImportThumbnailTrait::fetch_and_sideload_thumbnails()` for thumbnail sideloading.

Phase 5 P2.2 net delta vs the old hand-rolled importers: rtMedia 854 → 807 (-47), MediaPress 494 → 432 (-62), BuddyBoss 591 → 538 (-53). Total -213 lines.

| Command | Class | Source |
|---|---|---|
| `wp mvs migrate rtmedia` | `Integrations/RtMedia/Importer.php` | rtMedia plugin |
| `wp mvs migrate mediapress` | `Integrations/MediaPress/Importer.php` | BuddyPress MediaPress |
| `wp mvs migrate buddyboss` | `Integrations/BuddyBoss/Importer.php` | BuddyBoss Platform (multi-table — bp_media + bp_document + bp_video) |

---

## 11. Free services Pro consumes

Pro reaches into Free state via `Plugin::free_service('key')` (delegates to Free's `ServiceContainer`).

| Free service key | Used by | Purpose |
|---|---|---|
| `upload` | `Integrations/Flickr/Connector.php` | Sideload imported media into MVS |
| (+) `MediaRepository` | 20+ Pro files | Direct import (acknowledged tech debt; Phase 1 added `MediaRepositoryInterface` + `TemplateHelpersInterface` to retire even that) |

> Messaging-related service keys (`reports`, `follows`, `notifications`) were in the previous audit because Pro shipped a stale fork of Free's MessagingService. The 1.2.0 messaging cleanup deleted all 5 PHP files in `includes/Messaging/` plus `assets/js/messaging.{js,min.js}` and `templates/messages.php` — messaging now lives entirely in Free. Pro consumes only `upload` (for the Flickr connector sideload).

---

## 12. Free filters Pro hooks

50 listeners total (see [`derived/cross-plugin-coupling.json`](derived/cross-plugin-coupling.json)) — 13 against hooks fired by Free, 37 against Pro-owned hooks. The high-impact integration filters:

| Filter / Action | Pro consumer | Behavior |
|---|---|---|
| `mvs_storage_driver` | `Plugin::register_storage_driver` | Returns `Integrations\AmazonS3\StorageDriver` or `Integrations\BunnyCDN\StorageDriver` based on `mvs_storage_provider` setting |
| `mvs_ai_providers` | `Plugin::register_ai_providers` | Registers `Integrations\GoogleVision\AIProvider` + `Integrations\Rekognition\AIProvider` on Free's AIService |
| `mvs_generate_watermark` | `Core/Watermarker::generate` | Reads via `$file_path`; writes preview to `wp-content/uploads/wpmediaverse/previews/` |
| `mvs_stats_tabs` | `Plugin::add_stats_tabs` | Injects "Video Analytics" admin tab |
| `mvs_moderation_tabs` | `Plugin::add_moderation_tabs` | Injects "User Reports" admin tab |
| `mvs_upload_args` | `Quota/QuotaService::enforce_quota` | Quota gate before upload accepted |
| `mvs_media_uploaded` | `Quota/QuotaService::increment_usage`, `Streaks/StreakService::on_upload`, `Connectors/ConnectorManager::maybe_auto_export`, `Captions/TranscriptionService::on_media_uploaded`, `Video/TranscodeService::maybe_queue` | Per-feature reactions to a successful upload |
| `mvs_media_response` | `Captions/TranscriptionService::append_captions_to_response`, `Video/ChapterService::append_chapters_to_response`, `Privacy/PrivacyUIService::append_privacy_options` | Decorates Free's media REST response with Pro fields |

---

## 13. Security boundaries (Pro-specific)

- **Inherits Free's gate.** `.htaccess` deny-all on `wp-content/uploads/wpmediaverse/`.
- **URL signing.** Pro uses Free's `MediaUrl::for_file` / `for_thumbnail` / `resolve` and `SignedUrlService` (no Pro-side signing infrastructure).
- **Cover-image handling.** `ChallengeService::format_challenge` and `TournamentService::format_tournament` route stored `cover_image_url` through `MediaUrl::resolve` and fall back to most-recent entry's signed thumbnail. Templates use `.mvs-card-cover-wrap` placeholder when cover absent.
- **Whisper path resolution.** `TranscriptionService:332` reads `MediaRepository::get(file_url)` to derive a filesystem path for `Integrations\Whisper\CaptionProvider` to read directly — never emits the URL. Annotated `// CI: storage-internal`.
- **Watermark.** Pro's `Watermarker::generate` reads via `$file_path` (filesystem); the URL filter arg is informational only.

---

## 14. Gutenberg Blocks (1.2.0)

12 server-rendered Pro blocks under category `wpmediaverse-pro`. Each block's `render.php` instantiates the appropriate Renderer or Layout class and delegates — **zero DB queries inside any block file**. Every block accepts the 20 standard layout/spacing/border/shadow/visibility attributes (injected by `WPMediaVersePro\Blocks\StandardAttributes::inject` via the `block_type_metadata` filter) plus its block-specific attrs. Per-instance scoped CSS is collected by `MVS_CSS::add()` and dumped on `wp_footer`, keyed off `mvs-block-{uniqueId}`.

| Block | Title | Source | Renderer / Layout (FQN) | Block-specific attrs | Feature toggle | Shortcode equivalent |
|---|---|---|---|---|---|---|
| `mvs/pro-tournament` | Tournament | `src/blocks/pro-tournament/` | `WPMediaVersePro\Tournaments\Renderer::render_single` | `tournamentId` | `mvs_tournaments_enabled` | `[mvs_pro_tournament id="…"]` |
| `mvs/pro-tournaments-list` | Tournaments List | `src/blocks/pro-tournaments-list/` | `WPMediaVersePro\Tournaments\Renderer::render_list` | — | `mvs_tournaments_enabled` | `[mvs_pro_tournaments_list]` |
| `mvs/pro-challenge` | Photo Challenge | `src/blocks/pro-challenge/` | `WPMediaVersePro\Challenges\Renderer::render_single` | `challengeId` | `mvs_challenges_enabled` | `[mvs_pro_challenge id="…"]` |
| `mvs/pro-challenges-list` | Challenges List | `src/blocks/pro-challenges-list/` | `WPMediaVersePro\Challenges\Renderer::render_list` | — | `mvs_challenges_enabled` | `[mvs_pro_challenges_list]` |
| `mvs/pro-battle` | Photo Battle | `src/blocks/pro-battle/` | `WPMediaVersePro\Battles\Renderer::render_single` | `battleId` | `mvs_battles_enabled` | `[mvs_pro_battle id="…"]` |
| `mvs/pro-battles-active` | Active Battles | `src/blocks/pro-battles-active/` | `WPMediaVersePro\Battles\Renderer::render_active` | — | `mvs_battles_enabled` | `[mvs_pro_battles_active]` |
| `mvs/pro-instagram-feed` | Instagram Feed | `src/blocks/pro-instagram-feed/` | `WPMediaVersePro\Frontend\Layouts\InstagramLayout::render_feed` (Rule 6: `enqueue_assets()` called in render.php) | `perPage`, `scope` | — | `[mvs_pro_instagram_feed per-page="…" scope="…"]` |
| `mvs/pro-flickr-feed` | Flickr Feed | `src/blocks/pro-flickr-feed/` | `WPMediaVersePro\Frontend\Layouts\FlickrLayout::render_feed` (Rule 6) | `perPage`, `scope` | — | `[mvs_pro_flickr_feed]` |
| `mvs/pro-pinterest-feed` | Pinterest Feed | `src/blocks/pro-pinterest-feed/` | `WPMediaVersePro\Frontend\Layouts\PinterestLayout::render_feed` (Rule 6) | `perPage`, `scope` | — | `[mvs_pro_pinterest_feed]` |
| `mvs/pro-dribbble-feed` | Dribbble Feed | `src/blocks/pro-dribbble-feed/` | `WPMediaVersePro\Frontend\Layouts\DribbbleLayout::render_feed` (Rule 6) | `perPage`, `scope` | — | `[mvs_pro_dribbble_feed]` |
| `mvs/pro-leaderboard` | Leaderboard | `src/blocks/pro-leaderboard/` | `WPMediaVersePro\Frontend\LeaderboardRenderer::render` (self-contained — no template) | `source` (`reactions`/`media_count`/`gamification_xp`), `perPage`, `window` (`all`/`30d`/`7d`) | — | `[mvs_pro_leaderboard source="…" per-page="…" window="…"]` |
| `mvs/pro-compete-hub` | Compete Hub | `src/blocks/pro-compete-hub/` | `WPMediaVersePro\Frontend\CompeteHubRenderer::render` | — | any of `mvs_tournaments_enabled` / `mvs_challenges_enabled` / `mvs_battles_enabled` | `[mvs_pro_compete_hub]` |

**Editor preview pattern:** `<ServerSideRender>` is intentionally NOT used (Interactivity API stores don't run in the editor's static SSR iframe — every conditional state would render stacked). Blocks instead use `src/blocks/shared/block-preview-card.js` — a static React component showing icon + title + description + status. The frontend path (`render.php` → renderer) is unchanged.

**Standard attributes (canonical Wbcom block standard, ported from wbcom-essential v4.5.0):** per-side `padding`/`margin`/`borderRadius` objects with `*Tablet`/`*Mobile` responsive variants, `*Unit` strings (px/em/rem/%), full typography schema, shadow with `shadowSpread`, visibility (`hideOnDesktop`/`hideOnTablet`/`hideOnMobile`). Base CSS, design tokens, theme isolation, and 7 named inspector components live under `src/shared/` (mirrors `wbcom-essential/plugins/gutenberg/src/shared/` with `wbe`→`mvs` prefix swaps).

---

## 15. Shortcodes (1.2.0)

Twelve `[mvs_pro_*]` shortcodes — one per Gutenberg block in §14. Same renderer call as the matching block, plus the 20 standard kebab-case attrs translated to camelCase by `Blocks\Shortcodes::kebab_to_camel()` (e.g. `padding-desktop` → `paddingDesktop`, `border-radius` → `borderRadius`, `hide-on-mobile` → `hideOnMobile`).

Block-specific shortcode attrs:

| Tag | Block-specific attrs | Renderer |
|---|---|---|
| `mvs_pro_tournament` | `id` | `Tournaments\Renderer::render_single` |
| `mvs_pro_tournaments_list` | — | `Tournaments\Renderer::render_list` |
| `mvs_pro_challenge` | `id` | `Challenges\Renderer::render_single` |
| `mvs_pro_challenges_list` | — | `Challenges\Renderer::render_list` |
| `mvs_pro_battle` | `id` | `Battles\Renderer::render_single` |
| `mvs_pro_battles_active` | — | `Battles\Renderer::render_active` |
| `mvs_pro_instagram_feed` | `per-page`, `scope` | `Frontend\Layouts\InstagramLayout::render_feed` |
| `mvs_pro_flickr_feed` | `per-page`, `scope` | `Frontend\Layouts\FlickrLayout::render_feed` |
| `mvs_pro_pinterest_feed` | `per-page`, `scope` | `Frontend\Layouts\PinterestLayout::render_feed` |
| `mvs_pro_dribbble_feed` | `per-page`, `scope` | `Frontend\Layouts\DribbbleLayout::render_feed` |
| `mvs_pro_leaderboard` | `source`, `per-page`, `window` | `Frontend\LeaderboardRenderer::render` |
| `mvs_pro_compete_hub` | — | `Frontend\CompeteHubRenderer::render` |

All registered in `WPMediaVersePro\Blocks\Shortcodes::init` (file: `includes/Blocks/Shortcodes.php`).

---

## 16. Migration Tools — shell + per-platform cards (Phase 5 P2.1)

Before 1.2.0 P2.1, `Admin/MigrationPage.php` was a 1,866-line god class mixing 3 platform-specific migration pipelines. After P2.1, it is a 627-line generic shell — admin menu entry, page-level UI/JS, AJAX dispatcher (`mvs_migration_batch` / `mvs_migration_reset`) — that hosts one card per registered platform-specific `MigrationAdmin`. AJAX requests carry a `platform` slug; the dispatcher routes to the right card's `run_batch()`.

**Abstract base:** `WPMediaVersePro\Integrations\AbstractMigrationAdmin` defines the contract: `slug()`, `label()`, `description()`, `icon()`, `is_available()`, `count_total()`, `count_imported()`, `not_found_message()`, `run_batch()`, `extra_card_html()`. Plus shared state helpers: `get_state()` / `save_state()` / `clear_state()`, and `add_to_album()` for cross-platform album integration.

**Three subclasses:**

| Card | File | Lines | Responsibility |
|---|---|---|---|
| rtMedia | `Integrations/RtMedia/MigrationAdmin.php` | 394 | Detection, count, batched import, album creation, rtMedia activity rewriting |
| MediaPress | `Integrations/MediaPress/MigrationAdmin.php` | 278 | Detection, count, batched import (single-table source) |
| BuddyBoss | `Integrations/BuddyBoss/MigrationAdmin.php` | 453 | Detection, count, batched import across `bp_media`/`bp_document`/`bp_video` (multi-table source) |

Two pre-existing bugs got fixed at the same time:
1. Detect queries used `_mvs_*`-prefixed meta keys while the import path stamped non-prefixed keys → "Imported" count was always 0.
2. MediaPress `run_batch_mediapress()` was missing `global $wpdb;` so its dedup query ran against undefined `$wpdb` and never short-circuited duplicates.

---

## 17. Coding rules

`bin/coding-rules-check.sh` enforces the following six numbered rules; rule violations fail the local-CI gate (`composer ci`).

| # | Rule | Notes |
|---|---|---|
| 1 | Never use `current_user_can()` with a custom-ability slug | Native caps only |
| 2 | REST `permission_callback` must not be the bare `'__return_true'` | Allowlist enforced |
| 3 | Never import a Free CONCRETE class directly | `MediaRepository` is the acknowledged exception (Phase 1 boundary interfaces in flight to retire it) |
| 4 | Platform-coupled code lives under `includes/Integrations/<Platform>/` | Never under capability-named subdirs (`Storage/`, `AI/`, `Quota/Adapters/`, `Connectors/Flickr/`); rejects re-introductions at the legacy paths |
| 5 (added 2026-05-02) | Every `wp_ajax_*` registration is on the documented 6-handler allowlist | Off-list registrations fail; new functionality must use a REST route. Each allowlisted registration carries an inline `Rule A allowlist:` comment. |
| 6 (added 2026-05-03) | Every block `render.php` that instantiates a `WPMediaVersePro\Frontend\Layouts\*` class MUST call `$layout->enqueue_assets()` in the same file | WordPress does NOT auto-enqueue per-layout stylesheets when the block path is taken — only the LayoutManager site-wide path does. Idempotent (`wp_enqueue_*` dedupes by handle). The bug class this rule prevents shipped briefly in 1.2.0 (Phase 3d): the 4 feed blocks' SVG stat icons rendered at viewBox-default size instead of the styled 14×14px from `dribbble.css` etc. |

Plus six non-numbered rules (also enforced or documented):

- Same standards as Free (`PHP-ORGANIZATION-RULES.md`, `NAMING-RULES.md`).
- **Feature toggles required** — every new Pro feature gated by `get_option('mvs_*_enabled')`.
- **CLI importer base class** — the 3 importers extend `WPMediaVersePro\Integrations\AbstractBatchImporter` (Phase 5 P2.2). New importers go under `includes/Integrations/<Platform>/Importer.php` (Rule 4) and extend the base.
- **Admin migration card pattern** — each platform-specific MigrationAdmin extends `WPMediaVersePro\Integrations\AbstractMigrationAdmin` (Phase 5 P2.1); the generic `Admin/MigrationPage` shell hosts one card per registered MigrationAdmin and dispatches AJAX by platform slug.
- **Admin page pattern** — follows Free (no standalone admin pages outside the WPMediaVerse menu hierarchy).
- **Layout interface contract** — every layout in `Frontend/Layouts/` implements `LayoutMode::render_feed(array $args = []): string` (Phase 3a). Returns ONLY the feed body markup — never `get_header()`/`get_footer()` — so blocks + shortcodes can embed the output anywhere.

---

## 18. Static analysis snapshot (2026-05-03)

See [`derived/`](derived/) for the machine-readable caches; [`wppqa-baseline-2026-05-03/SUMMARY.md`](wppqa-baseline-2026-05-03/SUMMARY.md) for the bug-finder baseline.

| Check | Status | Detail |
|---|---|---|
| wppqa plugin-dev-rules | 7 pass / 2 fail / **0 real bugs** | Both `confirm-banned` errors are false positives — modal-first-with-fallback pattern in `connector-settings.js:313` and `dashboard-connectors.js:136` |
| wppqa rest-js-contract | 44 pass / 0 fail | Messaging cleanup eliminated the previous baseline's drift findings; new Pro blocks delegate to typed Renderer classes (zero direct REST property access in `src/blocks/pro-*/`) |
| wppqa wiring-completeness | 48 pass / 0 fail | Every Pro setting registered in `ProSettings` has a runtime reader |
| Cross-plugin coupling | 50 listeners (-3 vs 2026-05-01) | `mvs_message_sent` listener removed with the entire `includes/Messaging/` directory |
| PHPStan baseline (level 5) | 89 ignore blocks / 141 ignored errors (was 158) | Messaging cleanup dropped 17 entries; ~75 of the 141 are pure noise from missing PHPStan stubs (`MVS_PRO_*` constants, `WP_CLI` class, `WPINC`, Action Scheduler) — clearable with one `phpstan.neon` edit |
| Registry-pattern surfaces | 6 internal slots | `storage_driver`, `ai_provider`, `connector`, `quota_membership_adapter`, `layout_mode`, `migration_admin_card` — five use explicit-id strategy; AI providers use all-active-with-credentials |
| REST hang risks (block-side) | 0 | All 12 Pro blocks delegate to server-side renderers; zero `apiFetch`/`fetch` calls in `src/blocks/pro-*/` |
| Grid 1fr collapse risks | 0 | All 33 `grid-template-columns` declarations use safe patterns (`minmax`, `auto-fill`, fixed-track-with-1fr-pair, or single-column inside `@media`) |

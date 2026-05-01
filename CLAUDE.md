# WPMediaVerse — AI Quick Reference

> **READ FIRST:** [`audit/manifest.summary.json`](audit/manifest.summary.json) is a ≤2 KB index — load it first. The full inventory in [`audit/manifest.json`](audit/manifest.json) (v2.2 schema) covers **51 REST endpoints, 3 plugin AJAX actions, 7 admin pages, 31 settings, 18 hooks fired (7 with Pro consumers), 21 tables, 12 blocks, 34 services**. Detail files: [`manifest.rest.json`](audit/manifest.rest.json), [`manifest.hooks.json`](audit/manifest.hooks.json), [`manifest.tables.json`](audit/manifest.tables.json). Cross-plugin coupling: [`audit/derived/cross-plugin-coupling.json`](audit/derived/cross-plugin-coupling.json). Bug-finder baseline: [`audit/wppqa-baseline-2026-05-01/SUMMARY.md`](audit/wppqa-baseline-2026-05-01/SUMMARY.md). Reports: [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`audit/CODE_FLOWS.md`](audit/CODE_FLOWS.md), [`audit/ROLE_MATRIX.md`](audit/ROLE_MATRIX.md), [`audit/graph.html`](audit/graph.html). Refresh: `/wp-plugin-onboard --refresh`.

## Quick Facts

| Key | Value |
|-----|-------|
| Version | 1.1.3 |
| PHP | >= 7.4 (header), target 8.1+ |
| WordPress | >= 6.5 |
| Namespace | `WPMediaVerse\` |
| Autoload | PSR-4 via Composer (`includes/`) |
| Text Domain | `wpmediaverse` |
| Custom Tables | 21 (prefixed `mvs_`) |
| REST Controllers | 19 (namespace `mvs/v1`) |
| Pro Extension Hook | `mvs_loaded` (fires with `ServiceContainer`) |
| Build | `npx grunt dist` |
| Entry Point | `wpmediaverse.php` -> `Plugin::init()` |
| Admin Slug | `wpmediaverse` |

---

## Module Map

| Namespace | Responsibility | Key Classes |
|-----------|---------------|-------------|
| `Core\` | Bootstrap, DI container, migrations, templates, settings helper | `Plugin`, `ServiceContainer`, `Migrator`, `Loader`, `Activator`, `Deactivator`, `TemplateLoader`, `TemplateHelpers`, `Abilities`, `SettingsHelper` |
| `Admin\` | WP admin pages, moderation queue | `OverviewPage`, `StatsPage`, `ModerationQueue`, `LogViewerPage`, `SetupWizard`, `CollectionMetaBox`, `MediaListPage` |
| `Admin\Settings\` | Settings page (5 focused classes) | `SettingsPage`, `SettingsRegistrar`, `FieldRenderer`, `PermissionsManager`, `Sanitizers` |
| `REST\Controller\` | REST API endpoints (18 controllers) | `MediaController`, `AlbumController`, `CollectionController`, `BulkController`, `ReactionController`, `CommentController`, `FavoriteController`, `StatsController`, `TagController`, `ModerationController`, `AccessController`, `SignedUrlController`, `FollowController`, `NotificationController`, `UserController`, `ReportController`, `ActivityController`, `ProfileController` |
| `REST\` | Rate limiting middleware | `RateLimiter` |
| `Services\` | Business logic, storage, AI, caching, URL signing | `UploadService`, `StorageService`, `PrivacyService`, `AlbumService`, `CollectionService`, `StoryService`, `AIService`, `OpenAIProvider`, `ModerationService`, `StatsService`, `AccessRulesService`, `SignedUrlService`, `WatermarkService`, `CacheService`, `LoggerService`, `GDPRService`, `HealthCheckService`, `ProfileService`, `LocalDriver`, `MediaUrl` |
| `Social\` | Social interactions (reactions, comments, follows) | `ReactionService`, `CommentService`, `FavoriteService`, `MentionService`, `ShareService`, `FollowService`, `NotificationService`, `ReportService`, `ActivityService` |
| `Integrations\` | Third-party platform bridges | `WebhookService` |
| `Integrations\BuddyPress\` | BuddyPress integration (7 focused classes) | `BuddyPressManager`, `ActivitySyncIntegration`, `ActivityContentIntegration`, `ProfileTabIntegration`, `GroupTabIntegration`, `NotificationIntegration`, `ActivityFormIntegration`, `MediaDisplayHelper` |
| `PostTypes\` | Custom post type registration | `Album`, `Collection` |
| `Taxonomies\` | Custom taxonomy registration | `MediaTag`, `MediaCategory` |
| `Blocks\` | Gutenberg block registration | `BlockRegistrar` |
| `Shortcodes\` | Legacy shortcode support | `Shortcodes` |
| `CLI\` | WP-CLI commands | `Commands` |
| `Messaging\` | Direct messaging engine + REST routes | `MessagingService`, `MessagingController`, `NotificationListener`, `RestPollingTransport`, `TransportInterface` |
| `Repository\` | Central data access layer | `MediaRepository` |
| `Capabilities\` | Role/capability management | `MediaCapabilities` |

---

## Service Container Keys

Registered in `includes/Core/Plugin.php` via `register_services()` and `init_messaging()`.

| Key | Class | Line |
|-----|-------|------|
| `storage` | `StorageService` | 224 |
| `upload` | `UploadService` | 231 |
| `admin.overview` | `OverviewPage` | 238 |
| `admin.settings` | `SettingsPage` | 245 |
| `privacy` | `PrivacyService` | 252 |
| `reactions` | `ReactionService` | 259 |
| `comments` | `CommentService` | 266 |
| `favorites` | `FavoriteService` | 273 |
| `mentions` | `MentionService` | 280 |
| `shares` | `ShareService` | 287 |
| `stats` | `StatsService` | 294 |
| `albums` | `AlbumService` | 301 |
| `collections` | `CollectionService` | 308 |
| `stories` | `StoryService` | 315 |
| `ai` | `AIService` (+ `OpenAIProvider`) | 324 |
| `moderation` | `ModerationService` | 341 |
| `admin.moderation` | `ModerationQueue` | 350 |
| `admin.stats` | `StatsPage` | 357 |
| `admin.logs` | `LogViewerPage` | 364 |
| `admin.setup_wizard` | `SetupWizard` | 371 |
| `admin.collection_metabox` | `CollectionMetaBox` | 378 |
| `access_rules` | `AccessRulesService` | 387 |
| `signed_urls` | `SignedUrlService` | 394 |
| `watermark` | `WatermarkService` | 401 |
| `integration.buddypress` | `BuddyPressIntegration` | 410 |
| `integration.webhooks` | `WebhookService` | 419 |
| `cache` | `CacheService` | 428 |
| `follows` | `FollowService` | 454 |
| `notifications` | `NotificationService` | 461 |
| `reports` | `ReportService` | 470 |
| `activity` | `ActivityService` | 477 |
| `profile` | `ProfileService` | 486 |
| `messaging` | `MessagingService` | 992 |
| `media_repository` | `MediaRepository` | 496 |

**34 services total.** Plus a non-container static helper: `Services\MediaUrl` (single signing entry point for non-REST callers — added 1.1.3 patch).

---

## Custom Tables (21)

All prefixed with `{$wpdb->prefix}mvs_`. Defined in `includes/Core/Migrator.php`.

| Table | Purpose |
|-------|---------|
| `mvs_media_index` | Authoritative media record (no CPT dependency) |
| `mvs_media_meta` | Arbitrary key-value metadata for media |
| `mvs_media_views` | Per-user view tracking |
| `mvs_media_stats` | Aggregated media statistics |
| `mvs_reactions` | Emoji reactions on media |
| `mvs_favorites` | User favorites/bookmarks |
| `mvs_follows` | User-to-user follow relationships |
| `mvs_mentions` | @mention records |
| `mvs_activity` | Activity feed entries |
| `mvs_notifications` | User notification queue |
| `mvs_reports` | Content/user abuse reports |
| `mvs_blocks` | User block list |
| `mvs_access_rules` | Per-media access control rules |
| `mvs_access_grants` | Granted access tokens |
| `mvs_album_items` | Album-to-media mapping |
| `mvs_error_log` | Internal error/debug log |
| `mvs_conversations` | DM conversation threads |
| `mvs_conversation_participants` | Conversation membership |
| `mvs_messages` | Individual DM messages |
| `mvs_message_reactions` | Reactions on DM messages |
| `mvs_transactions` | Credit/monetization transactions |

---

## Coding Rules

This is the index. Every rule below links to its full spec in `qa/`. Add new rules here first (1-2 sentence canonical version), then write the detailed spec.

1. **Max file size: 500 lines.** Files above this are tech debt (see Known Debt below). Spec: `qa/PHP-ORGANIZATION-RULES.md` §1.
2. **Max method size: 50 lines.** Extract helpers or delegate to services. Spec: `qa/PHP-ORGANIZATION-RULES.md` §1.
3. **Database queries: always `$wpdb->prepare()`.** No raw interpolation.
4. **Admin HTML: template files only.** Never inline `echo` of HTML or `<script>` in PHP classes; use `templates/admin/`. Spec: `qa/PHP-ORGANIZATION-RULES.md` §2–§3.
5. **Hook names: `mvs_` prefix, snake_case.** Example: `mvs_media_uploaded`, `mvs_ai_providers`. Spec: `qa/NAMING-RULES.md` §5.
6. **REST: extend `WP_REST_Controller`.** Every endpoint must define `get_item_schema()` and `get_item_permissions_check()` / `permission_callback`.
7. **Security: nonce + capability on every write.** Use `wp_verify_nonce()` for admin forms, `permission_callback` for REST.
8. **Error handling: `WP_Error` or `LoggerService`.** No silent `return false` — log failures. Spec: `qa/PHP-ORGANIZATION-RULES.md` §5.
9. **i18n: all user-facing strings wrapped.** Use `__()`, `esc_html__()`, `esc_attr__()` with text domain `wpmediaverse`. Spec: `qa/NAMING-RULES.md` §10.
10. **Pro boundary: never import Free classes directly.** Pro hooks into `mvs_loaded` and uses `ServiceContainer` — no `use WPMediaVerse\...` in Pro code. Spec: `qa/PHP-ORGANIZATION-RULES.md` §9.
11. **No silent render fallthrough.** Every `return;` inside a render path (block `render.php`, shortcode callback, template, admin list, widget) must be paired with a visible empty state. Use `TemplateHelpers::render_block_empty_state()` / `render_admin_empty_state()`. Bare returns are only acceptable in hook callbacks, cron handlers, and REST permission checks. Spec: `qa/RENDER-STATE-RULES.md`.
12. **CSS file ownership.** BP rules live in `bp-integration.css` (scoped under `#buddypress`). `frontend.css` is for generic plugin frontend only. Admin rules in `admin.css`. Messaging in `messaging.css`. Block-specific in `src/blocks/*/style.css`. Every BP-touching integration enqueues both `mvs-frontend` and `mvs-bp-integration`. No duplicate class-vs-ID rules. No `!important` without a one-line comment explaining what theme rule it fights. No dead selectors (every `.mvs-*` / `#mvs-*` must have an emitter). Spec: `qa/CSS-ORGANIZATION-RULES.md`. Locked in `qa/WHAT-TO-CHECK.md` regression row "BP CSS file ownership".
13. **Names don't lie.** Class names, hook names, CSS classes must match actual usage. A `.mvs-bp-X` class used outside BP is a bug; either rename or narrow usage. Spec: `qa/NAMING-RULES.md`.
14. **Sibling classes with ≥50% duplicate method bodies share a base class.** At n=2 duplication is tolerable; at n=3 it must be extracted. Spec: `qa/PHP-ORGANIZATION-RULES.md` §6.
15. **Debt tax.** No PR adds lines to files in the Known Debt table below. Every edit to a debt file must reduce its line count or extract code out, unless the PR body justifies the addition. Spec: `qa/PROCESS-RULES.md` §3.

**Process meta:** how rules are added, checked, and retired — `qa/PROCESS-RULES.md`.

---

## Known Debt (Do Not Worsen)

| File | Lines | Status |
|------|------:|--------|
| `includes/Integrations/BuddyPress/` | DONE | Split into 7 classes (was 2,811-line god class) |
| `includes/Admin/Settings/` | DONE | Split into 5 classes (was 2,401-line god class) — except `SettingsRegistrar.php`, see below |
| `includes/Messaging/MessagingService.php` | 1,606 | God class — extract conversation/message sub-services |
| `includes/Core/Plugin.php` | 1,208 | Bootstrap monolith — acceptable but avoid adding logic |
| `includes/REST/Controller/MediaController.php` | 1,105 | Largest controller — extract bulk/filter helpers |
| `includes/Admin/Settings/SettingsRegistrar.php` | 928 | Added 2026-04-24 after audit. Consolidates 7 settings groups into one class — extract per-group registrars. |
| `includes/Services/UploadService.php` | 911 | Added 2026-04-24. Mixes validation, type detection, storage routing, progress tracking. Extract ValidatorService + ProgressTrackerService. |
| `includes/Repository/MediaRepository.php` | 820 | Added 2026-04-24. Single data-access layer for 8+ query patterns. Extract MediaQueryBuilder + MediaStatsRepository. |
| `includes/Messaging/MessagingController.php` | 803 | Large controller — extract route handlers |
| `includes/Integrations/BuddyPress/ProfileTabIntegration.php` | 736 | Added 2026-04-24. 80% duplicate with GroupTabIntegration — extract BaseBPTabIntegration. |
| `includes/Integrations/BuddyPress/GroupTabIntegration.php` | 712 | Added 2026-04-24. 80% duplicate with ProfileTabIntegration — extract BaseBPTabIntegration. |

**Debt tax (Coding Rule #15):** No PR adds lines to any file above. Every edit to a debt file must reduce its line count or extract code out. If the change truly can't reduce the file, the PR body must explicitly justify the addition — that justification is what future reviewers use to decide whether the file should escape the debt list.

---

## Testing

| Key | Value |
|-----|-------|
| Framework | PHPUnit 9.6 + yoast/phpunit-polyfills 2.x |
| Test dir | `tests/unit/` |
| Test files | 11 (`CapabilitiesTest`, `CLITest`, `CommentServiceTest`, `FavoriteServiceTest`, `FollowServiceTest`, `MediaMetaTest`, `PostTypesTest`, `PrivacyTest`, `ReactionServiceTest`, `RESTApiTest`, `SampleTest`) |
| Coverage | ~10% — new code must include tests |
| Run | `./vendor/bin/phpunit` |
| Config | `phpunit.xml.dist` |

---

## Build & Release

| Command | Purpose |
|---------|---------|
| `npx grunt build` | RTL CSS, minify CSS/JS, generate .pot |
| `npx grunt dist` | Full build + clean dist/ + copy + ZIP |
| `npx grunt release` | CI check + dist |
| `composer run phpcs` | WPCS coding standards |
| `composer run phpstan` | Static analysis (baseline: `phpstan-baseline.neon`) |
| `./vendor/bin/phpunit` | Unit tests |

### Static Analysis Config

- `phpcs.xml` — WPCS ruleset
- `phpstan.neon.dist` — PHPStan config with WordPress stubs
- `phpstan-baseline.neon` — Known issues baseline

---

## Key Directories

| Path | Contents |
|------|----------|
| `includes/` | All PHP source (PSR-4 autoloaded) |
| `src/` | JavaScript/block source |
| `build/` | Compiled block assets |
| `assets/` | Frontend CSS/JS |
| `templates/` | PHP template files |
| `languages/` | Translation files (.pot/.po/.mo) |
| `tests/` | PHPUnit tests + bootstrap |
| `plan/` | Development plans and status docs |
| `dist/` | Release ZIP (generated) |
| `docs/` | Documentation |

---

## QA

All lives in `qa/`. Content is inventory-style (what must be true), not process.

- `qa/WHAT-TO-CHECK.md` — flat list: surfaces, actions, settings, data stores, contracts.
- `qa/RENDER-STATE-RULES.md` — every render surface must emit a populated or empty state; no bare `return;` in render paths.
- `qa/MANUAL-UX-QA.md` — procedural walkthrough.
- Pro has its mirror at `../wpmediaverse-pro/qa/`.

At release: can the plugin demonstrate the things in `WHAT-TO-CHECK.md`? Yes → ship. No → fix what's broken.

---

## Recent Changes

_Updated after each commit._

| Date | Commit | Summary |
|------|--------|---------|
| 2026-04-30 | (pending) | Add `Core\SettingsHelper` — canonical static accessor for paired-plugin settings reads. First slot is page-id family (`dashboard`/`explore`/`upload`) with `mvs_page_id_{slot}` filter. Pro/themes must use this instead of `get_option('mvs_page_*')` (Free invariant A4). Also covers `mvs_thumbnail_size` and `mvs_openai_api_key`. |
| 2026-04-30 | (pending) | Populate `hooks_fired[].args_signature[]` in `audit/manifest.json` for paired-plugin contract verification. 14 of 22 hooks now carry full type-annotated arg shapes; 8 phantom entries (manifest drift — listed but no `do_action`/`apply_filters` exists in source) marked with `_status: no_call_site_found`. PRO arch-checks A11 consumes this. |
| 2026-05-01 | `d986525` | Fix: DM-access dropdown silently rewriting Nobody/Mutual to Everyone — duplicate `register_setting()` overwrote enum sanitizer with bool sanitizer. Modified Sanitizers.php + SettingsRegistrar.php. Regression journey: `audit/journeys/customer/05-dm-access-setting-persists.md`. |
| 2026-05-01 | (skill run) | `/wp-plugin-onboard --refresh` regenerated manifest to v2.2 (added `mvs_storage_driver` + `mvs_comment_edit_window` settings, populated `consumed_by[]` on 7 Free hooks); dropped local-CI scaffold + 5 critical journeys; wppqa baseline saved (no new findings vs 2026-04-29). |
| 2026-04-29 | `ab21046` | Sign every upload-URL emission path: BP activity rebuild signs through MediaUrl; AIService analyze/auto_tag/moderate use signed URLs (no raw fallback); MediaDisplayHelper href fallback signed; TemplateHelpers defensive fallback returns ''; bin/ci-local.sh honors `// CI: storage-internal` markers; new `Services/MediaUrl.php` static helper as single signing entry point |
| 2026-04-29 | `c32e060` | 1.2.0 milestone planning docs forked off into `1.1.4` branch; new `1.2.0` rebased fresh from main |
| 2026-04-29 | (skill run) | `/wp-plugin-onboard` regenerated `audit/manifest.json` + `FEATURE_AUDIT.md` + `CODE_FLOWS.md` |
| 2026-04-29 | e7deb82 | Lightbox: full-viewport Facebook-style layout; full-res images; close button fix; Lucide icons everywhere |
| 2026-04-24 | `8f63b3b` → `df15593` | Architectural split of BP CSS into `bp-integration.css`; CSS file ownership rule #12; QA regression rows locked |
| 2026-04-23 | — | Added `qa/` canonical QA home; Coding Rule #11 "no silent render fallthrough" |
| 2026-04-21 | 080fd94 | 1.1.2: grid cols=5, stats filters, tag cloud count, lightbox favorite/share fixes |
| 2026-04-10 | 632e955 | 1.1.1: signed URL fixes, anonymous access, DM notifications, messaging page fixes |

---

## Local CI pipeline (REQUIRED before push)

This plugin has a self-contained local-CI gate. No external service runs the gate — every contributor runs it on their own machine, and the pre-push git hook runs it automatically before every `git push`.

```bash
composer install-hooks    # one-time per clone — activates bin/git-hooks/pre-push
composer ci               # full pipeline (~30s + browser journeys)
composer ci:no-journeys   # everything except browser-dependent journeys (~25s)
composer ci:quick         # PHP lint + coding-rules only (~10s, for tight loops)
composer arch-checks      # Free/Pro contract invariants (Pro plugin only)
composer journeys:dry-run # list configured journeys without executing
```

What the gate runs (in order, see `bin/local-ci.sh`):

| Stage | Tool | Catches |
|---|---|---|
| 1.1 PHP lint | `php -l` on every changed source | syntax errors |
| 1.2 WPCS | `composer phpcs` | WordPress coding standards |
| 1.3 PHPStan | `composer phpstan` | static type errors |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific rules |
| 2.2 (Pro only) | `bin/architecture-checks.sh` | Free/Pro contract invariants |
| 3.1 Manifest | `jq` on `audit/manifest.json` | manifest validity + freshness |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end |

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

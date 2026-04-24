# WPMediaVerse — AI Quick Reference

## Quick Facts

| Key | Value |
|-----|-------|
| Version | 1.1.2 |
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
| `Core\` | Bootstrap, DI container, migrations, templates | `Plugin`, `ServiceContainer`, `Migrator`, `Loader`, `Activator`, `Deactivator`, `TemplateLoader`, `TemplateHelpers`, `Abilities` |
| `Admin\` | WP admin pages, moderation queue | `OverviewPage`, `StatsPage`, `ModerationQueue`, `LogViewerPage`, `SetupWizard`, `CollectionMetaBox`, `MediaListPage` |
| `Admin\Settings\` | Settings page (5 focused classes) | `SettingsPage`, `SettingsRegistrar`, `FieldRenderer`, `PermissionsManager`, `Sanitizers` |
| `REST\Controller\` | REST API endpoints (18 controllers) | `MediaController`, `AlbumController`, `CollectionController`, `BulkController`, `ReactionController`, `CommentController`, `FavoriteController`, `StatsController`, `TagController`, `ModerationController`, `AccessController`, `SignedUrlController`, `FollowController`, `NotificationController`, `UserController`, `ReportController`, `ActivityController`, `ProfileController` |
| `REST\` | Rate limiting middleware | `RateLimiter` |
| `Services\` | Business logic, storage, AI, caching | `UploadService`, `StorageService`, `PrivacyService`, `AlbumService`, `CollectionService`, `StoryService`, `AIService`, `OpenAIProvider`, `ModerationService`, `StatsService`, `AccessRulesService`, `SignedUrlService`, `WatermarkService`, `CacheService`, `LoggerService`, `GDPRService`, `HealthCheckService`, `ProfileService`, `LocalDriver` |
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

**34 services total.**

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

1. **Max file size: 500 lines.** Files above this are tech debt (see Known Debt below).
2. **Max method size: 50 lines.** Extract helpers or delegate to services.
3. **Database queries: always `$wpdb->prepare()`.** No raw interpolation.
4. **Admin HTML: template files only.** Never inline `echo` in PHP classes; use `templates/admin/`.
5. **Hook names: `mvs_` prefix, snake_case.** Example: `mvs_media_uploaded`, `mvs_ai_providers`.
6. **REST: extend `WP_REST_Controller`.** Every endpoint must define `get_item_schema()` and `get_item_permissions_check()` / `permission_callback`.
7. **Security: nonce + capability on every write.** Use `wp_verify_nonce()` for admin forms, `permission_callback` for REST.
8. **Error handling: `WP_Error` or `LoggerService`.** No silent `return false` — log failures.
9. **i18n: all user-facing strings wrapped.** Use `__()`, `esc_html__()`, `esc_attr__()` with text domain `wpmediaverse`.
10. **Pro boundary: never import Free classes directly.** Pro hooks into `mvs_loaded` and uses `ServiceContainer` — no `use WPMediaVerse\...` in Pro code.
11. **No silent render fallthrough.** Every `return;` inside a render path (block `render.php`, shortcode callback, template, admin list, widget) must be paired with a visible empty state. Use `TemplateHelpers::render_block_empty_state()` / `render_admin_empty_state()`. Bare returns are only acceptable in hook callbacks, cron handlers, and REST permission checks. Full rule: `qa/RENDER-STATE-RULES.md`.
12. **CSS file ownership.** `assets/css/bp-integration.css` owns **all** BuddyPress-specific CSS, scoped under `#buddypress` (and `.activity-list` for AJAX-injected activity items that render outside the wrapper). `assets/css/frontend.css` is for generic plugin frontend only: design tokens, templates, shortcodes, blocks, dashboard, single-media, lightbox. When adding a rule that targets BP surfaces (activity composer, activity stream, `/members/*`, `/groups/*`), put it in `bp-integration.css` — never in `frontend.css`. Every BP-touching integration (`ActivityFormIntegration`, `ProfileTabIntegration`, `GroupTabIntegration`) must enqueue both `mvs-frontend` and `mvs-bp-integration`. Rationale: keeps theme-compat and specificity concerns in one file, prevents unscoped rules from leaking into non-BP surfaces (e.g., our old broad `#buddypress .activity-content img` rule blew up Reign's `.bp-group-preview-cover` until we narrowed it). Locked in `qa/WHAT-TO-CHECK.md` regression row "BP CSS file ownership".

---

## Known Debt (Do Not Worsen)

| File | Lines | Status |
|------|------:|--------|
| `includes/Integrations/BuddyPress/` | DONE | Split into 7 classes (was 2,811-line god class) |
| `includes/Admin/Settings/` | DONE | Split into 5 classes (was 2,401-line god class) |
| `includes/Messaging/MessagingService.php` | 1,606 | God class — extract conversation/message sub-services |
| `includes/Core/Plugin.php` | 1,208 | Bootstrap monolith — acceptable but avoid adding logic |
| `includes/REST/Controller/MediaController.php` | 1,105 | Largest controller — extract bulk/filter helpers |
| `includes/Messaging/MessagingController.php` | 803 | Large controller — extract route handlers |

**Rule:** Do not add lines to these files. If you must change them, extract code out first.

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
| 2026-04-23 | — | Added `qa/` canonical QA home (`WHAT-TO-CHECK.md`, `RENDER-STATE-RULES.md`, `MANUAL-UX-QA.md`, `runs/`); Coding Rule #11 "no silent render fallthrough"; fixed F3/F4/F5/F6/P6 findings |
| 2026-04-24 | `8f63b3b` → `df15593` | Architectural split of BP CSS: moved all BP-specific rules (~2500 lines across 5 sections) out of `frontend.css` into `bp-integration.css`, scoped under `#buddypress`. Wired `ActivityFormIntegration` to enqueue `mvs-bp-integration`. Consolidated `.mvs-activity-media-btn` class-vs-ID duplicates. Removed dead `.theme-flavor` rules + broken dangling selector. Fixed Reign join-group cover image conflict (our broad catch-all was sizing theme-injected classless images). Added Coding Rule #12 "CSS file ownership". Locked in QA regression rows "BP CSS file ownership" and "attach-media + privacy alignment". |

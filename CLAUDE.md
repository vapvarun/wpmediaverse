# WPMediaVerse — AI Quick Reference

> **READ FIRST:** [`audit/manifest.summary.json`](audit/manifest.summary.json) is a ≤2 KB index — load it first. The full inventory in [`audit/manifest.json`](audit/manifest.json) (v2.2 schema) covers **51 REST endpoints, 3 plugin AJAX actions, 7 admin pages, 31 settings, 18 hooks fired (7 with Pro consumers), 21 tables, 12 blocks, 34 services**. Detail files: [`manifest.rest.json`](audit/manifest.rest.json), [`manifest.hooks.json`](audit/manifest.hooks.json), [`manifest.tables.json`](audit/manifest.tables.json). Cross-plugin coupling: [`audit/derived/cross-plugin-coupling.json`](audit/derived/cross-plugin-coupling.json). Bug-finder baseline: [`audit/wppqa-baseline-2026-05-01/SUMMARY.md`](audit/wppqa-baseline-2026-05-01/SUMMARY.md). Action-audit baseline: [`audit/action-audit-2026-05-01/SUMMARY.md`](audit/action-audit-2026-05-01/SUMMARY.md). Reports: [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`audit/CODE_FLOWS.md`](audit/CODE_FLOWS.md), [`audit/ROLE_MATRIX.md`](audit/ROLE_MATRIX.md), [`audit/graph.html`](audit/graph.html). Refresh: `/wp-plugin-onboard --refresh`.

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

> **Debt criterion (2026-05-03 update):** A file lands here only when it has a CONCRETE structural problem — duplicate sibling classes, multiple unrelated responsibilities, a 350-line method, etc. Size alone is not a reason. For a plugin at WPMediaVerse's scale (34 services, 19 controllers, Free + Pro pair), files in the 1k–3k range are normal and healthy as long as they're focused on one responsibility. The team splits at ~2.5k+ when a file's scope genuinely outgrows one class (BP manager was 2,811; Settings was 2,401 — both already split).

| File | Issue | Status |
|------|-------|--------|
| `includes/Integrations/BuddyPress/` | (was 2,811-line manager mixing 7 unrelated BP integration concerns) | DONE — split into 7 focused classes |
| `includes/Admin/Settings/` | (was 2,401-line registrar with 7 settings groups + UI + sanitizers in one) | DONE — split into 5 classes (except `SettingsRegistrar.php`, see below) |
| `includes/Integrations/BuddyPress/ProfileTabIntegration.php` ↔ `GroupTabIntegration.php` | (was 80% duplicate method bodies between the two siblings) | DONE 2026-05-03 (Phase 5 P2.4) — `BaseBPTabIntegration` extracted; subclasses now hold only context-specific overrides. |
| `includes/Admin/Settings/SettingsRegistrar.php` | Single class consolidates 7 distinct settings groups (each a coherent unit on its own). Extract per-group registrars when next touched. | OPEN |
| `includes/Services/UploadService.php` | Mixes 4 unrelated concerns: validation, type detection, storage routing, progress tracking. Extract ValidatorService + ProgressTrackerService when next touched. | OPEN |

**Files that are large but NOT debt** (mentioned because someone might wonder): `MessagingService.php` (1,596), `MessagingController.php` (803), `Plugin.php` (1,208), `MediaController.php` (1,105), `MediaRepository.php` (820). All are large because their domain is genuinely large, not because responsibilities are tangled. Don't propose splits unless a real organizational problem surfaces.

**Debt tax (Coding Rule #15):** No PR adds lines to a file in the OPEN rows above. Every edit must reduce the line count or extract code out. Files in the "large but NOT debt" list have no debt tax — edit them normally.

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
| 2026-05-03 | (1.2.0 Phase 5 P2.4 complete) | **`BaseBPTabIntegration` extracted.** `ProfileTabIntegration` (was 736 lines) and `GroupTabIntegration` (was 712 lines) shared ~80% of method bodies — upload form + JS, album creation form, album upload form + JS, asset enqueue, media grid, load-more, back link, single-album items grid. All shared rendering moved to a new abstract `BaseBPTabIntegration` (816 lines, mostly the duplicated upload IIFEs). Subclasses now hold only context-specific bits via abstract methods: `is_authorized()`, `fetch_media_ids()`, `get_albums_query_args()`, `album_belongs_to_context()`, `get_albums_index_url()`, six empty-state copy variants, `load_more_data_attrs()`, three upload-form extension hooks (`extra_upload_form_fields`, `extra_upload_js_vars`, `extra_upload_formdata_appends`), `render_sub_tabs()` (group renders inline; profile is no-op), `album_form_extra_fields()`. Public template methods (`media_content`, `albums_content`, `single_album_content`) are `final` so the orchestration is fixed. Profile subclass: 256 lines (BP nav setup + media-count badge + profile-specific overrides). Group subclass: 267 lines (BP subnav setup + group-specific overrides + the inline sub-tab nav). Browser-verified all 5 routes (profile media + albums + single album; group media + albums) at desktop and 390px mobile — empty-state copy correctly differs ("You haven't uploaded..." vs "No group media yet..."), inline group subnav renders, BP profile-nav count badge "Media 8" still updates. Net delta: -109 lines. Single bug fix on either tab now propagates to both — no more "fix BP tab N times". |
| 2026-05-03 | (1.2.0 Phase 7 complete) | **Block standard alignment (Free side)** — mirror of Pro Phase 3e for Free's 8 registered Gutenberg blocks (`media-upload`, `media-grid`, `media-player`, `album-viewer`, `story-viewer`, `media-stats`, `explore-feed`, `lock-overlay`). The 4 view-only Interactivity stores (`dashboard-view`, `explore-view`, `media-social`, `shared-ui`) are NOT registered as blocks so the standard inspector panels don't apply. **Step 1** (`926ab6e`) ported Pro's full `src/shared/` tree (17 files) to Free + the `src/blocks/shared/block-preview-card.{js,css}` component. Text-domain swapped `wpmediaverse-pro` → `wpmediaverse` in JS; CSS selectors tightened from `[class^="wp-block-wpmediaverse-pro"]` to `[class^="wp-block-mvs-"]` (catches Free's `wp-block-mvs-*` AND Pro's `wp-block-mvs-pro-*` since both plugins share the `mvs/` block namespace). `@wordpress/icons@^13` added as devDep (peer of SpacingControl + BorderRadiusControl + BoxShadowControl). **Step 2** (`0a0455e`) added `WPMediaVerse\Blocks\StandardAttributes` + `WPMediaVerse\Blocks\MVS_CSS` (PHP ports of Pro's). `BlockRegistrar::init()` now wires the `block_type_metadata` filter and the `wp_footer` hook. The filter gates on `mvs/` prefix BUT excludes `mvs/pro-` so Pro's own filter handles those. Each of the 8 registered blocks' `render.php` migrated: reads `$attributes['uniqueId']`, calls `MVS_CSS::add(...)`, and adds `mvs-block-{uniqueId}` + `visibility_classes()` to the wrapper class. **Step 3** (`1d664b9`) retrofitted all 8 `edit.js`: imports `StandardInspectorPanels` + `useUniqueId` from `src/shared/`, accepts `clientId`, calls `useUniqueId(clientId, ...)` on insert, mounts `<StandardInspectorPanels>` as the LAST child of `<InspectorControls>` so block-specific panels (Grid Settings, Filters, Features, etc.) appear FIRST. **Browser-verified** for `mvs/media-grid` at `/wp-admin/post-new.php`: sidebar shows Grid Settings + Filters + Features + Spacing + Border + Shadow + Visibility + Advanced in canonical order, 0 console errors. **Customer impact:** identical Spacing / Border / Shadow / Visibility panels across Free + Pro + wbcom-essential — one mental model regardless of which Wbcom plugin's block they're configuring. |
| 2026-05-01 | `060c1c8` | Settings-defaults audit (free): `mvs_ai_monthly_budget` registered default flipped from `0` (silently unlimited OpenAI spend) to `10` (USD/month cap); Activator seeds 10 on fresh install, Migrator v11 seeds 10 once for upgrades that never explicitly saved the option. Field description rewritten so "0 = unlimited" is no longer an invisible billing trap. |
| 2026-05-01 | `509bf9c` | Settings-defaults audit (free): Activator now auto-creates the Upload page (`[mvs_upload]`) alongside Explore + Dashboard so a fresh install ships with all three frontend surfaces wired up. SetupWizard pages step shows all three slots. New regression journey `audit/journeys/admin/06-activation-creates-pages.md`. |
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

| Stage | Tool | Catches | Status as of 2026-05-01 |
|---|---|---|---|
| 1.1 PHP lint | `php -l` on every changed source | syntax errors | ✅ exits 0 |
| 1.2 WPCS | `composer phpcs` | WordPress coding standards (errors only; warnings via `composer phpcs:full`) | ✅ exits 0 — 0 errors / 41 warnings on baseline |
| 1.3 PHPStan | `composer phpstan` | static type errors | ✅ exits 0 — `phpstan-baseline.neon` pins existing items |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific rules (Rule 1: no native cap checks, Rule 2: REST __return_true allowlist) | ⚠️ Rule 2 has 23 callsites awaiting Item-5 triage; allowlist will be populated as part of that work |
| 2.2 (Pro only) | `bin/architecture-checks.sh` | Free/Pro contract invariants | (Pro only) |
| 2.3 Settings contract | `composer test:contract` | register_setting whitelist alignment (catches the d986525 bug class) | ✅ exits 0 |
| 3.1 Manifest | `jq` on `audit/manifest.json` | manifest validity + freshness | ✅ exits 0 |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end | (skipped in `:no-journeys` mode) |

**Composer scripts (added 2026-05-01):**
- `composer phpcs` — runs WPCS errors only (`--warning-severity=0`); exits 0 on baseline.
- `composer phpcs:full` — runs WPCS with warnings shown (informational; still exits 0 if no errors).
- `composer phpcs:fix` — runs phpcbf to auto-fix fixable violations.
- `composer phpstan` — runs PHPStan with the existing baseline; exits 0.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

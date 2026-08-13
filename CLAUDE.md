# WPMediaVerse — AI Quick Reference

> **TRUST ORDER:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) (code-verified state) → [`CAPABILITIES.md`](CAPABILITIES.md) (what it does, in buyer language) → the code itself (wins on any disagreement). Dated files under `qa/runs/`, `audit/runs/` and `plan/` are history — verify before trusting.
>
> **Manifest refresh: agent-enumeration-only.** `write-manifest.mjs` cannot see this plugin's registration patterns (SettingsRegistrar, ServiceContainer::register(), MediaCapabilities, PostTypes\Album::register(), CLI subcommands as methods) and would zero out settings/services/wp_cli/capabilities/post_types/taxonomies. Refresh by targeted delta against ground-truth grep — never by committing generator output. See `generated.refresh_2026_08_05` in the manifest.
>
> **READ FIRST:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) is a ≤2 KB index — load it first. The full inventory in [`audit/manifests/manifest.json`](audit/manifests/manifest.json) (v2.2 schema) covers **114 REST endpoints, 3 plugin AJAX actions, 20 admin pages, 40 settings, 220 unique hooks fired (incl. 4 added in 1.6.0: `mvs_media_alt_text`, `mvs_reports_enabled` filters + `mvs_album_deleted`, `mvs_collection_deleted` actions; `mvs_media_deleted` now fires once from the `delete_cascade` funnel), 22 tables, 9 registered blocks (13 `block.json` dirs; 4 are Interactivity-only, not registered as editor blocks), 37 container-registered services, 20 WP-CLI subcommands (incl. `wp mvs backfill_ai`, `repair_storage`)**. Detail files: [`manifest.rest.json`](audit/manifests/manifest.rest.json), [`manifest.hooks.json`](audit/manifests/manifest.hooks.json), [`manifest.tables.json`](audit/manifests/manifest.tables.json). Cross-plugin coupling: [`audit/derived/cross-plugin-coupling.json`](audit/derived/cross-plugin-coupling.json). Bug-finder baseline: [`audit/runs/2026-05-03-wppqa-baseline-SUMMARY.md`](audit/runs/2026-05-03-wppqa-baseline-SUMMARY.md). Reports: [`audit/reports/FEATURE_AUDIT.md`](audit/reports/FEATURE_AUDIT.md), [`audit/reports/CODE_FLOWS.md`](audit/reports/CODE_FLOWS.md), [`audit/reports/ROLE_MATRIX.md`](audit/reports/ROLE_MATRIX.md), [`audit/graph.html`](audit/graph.html). Pro audit mirror: [`audit/pro/`](audit/pro/). Refresh: `/wp-plugin-onboard --refresh`.

## Quick Facts

| Key | Value |
|-----|-------|
| Version | 2.4.0 |
| PHP | >= 7.4 (header), target 8.1+ |
| WordPress | >= 6.5 |
| Namespace | `WPMediaVerse\` |
| Autoload | Hand-written PSR-4 in `wpmediaverse.php` (`WPMediaVerse\` → `includes/`). **Runtime never loads Composer.** |
| Runtime deps | Committed under `libs/` (Action Scheduler, EDD SL SDK) — see `libs/README.md` |
| `vendor/` | Dev and build tooling ONLY. Gitignored, not in the release zip, never loaded at runtime. |
| Text Domain | `wpmediaverse` |
| Custom Tables | 22 (prefixed `mvs_`) |
| REST Controllers | 23 (namespace `mvs/v1`) |
| Pro Extension Hook | `mvs_loaded` (fires with `ServiceContainer`) |
| Build | `npx grunt dist` |
| Entry Point | `wpmediaverse.php` -> `Plugin::init()` |
| Admin Slug | `wpmediaverse` |

---

## Module Map

| Namespace | Responsibility | Key Classes |
|-----------|---------------|-------------|
| `Core\` | Bootstrap, DI container, migrations, templates, settings helper, read-side URL facade | `Plugin`, `ServiceContainer`, `Migrator`, `Activator`, `Deactivator`, `TemplateLoader`, `TemplateHelpers`, `Abilities`, `SettingsHelper`, `MediaUrl` (+ `Loader`, **deprecated 2.4.0**, never used, removal 4.0.0) |
| `Admin\` | WP admin pages, moderation queue | `OverviewPage`, `StatsPage`, `ModerationQueue`, `LogViewerPage`, `SetupWizard`, `CollectionMetaBox`, `MediaListPage` |
| `Admin\Settings\` | Settings page (5 focused classes) | `SettingsPage`, `SettingsRegistrar`, `FieldRenderer`, `PermissionsManager`, `Sanitizers` |
| `REST\Controller\` | REST API endpoints (23 controllers) | `MediaController`, `AlbumController`, `CollectionController`, `BulkController`, `ReactionController`, `CommentController`, `FavoriteController`, `StatsController`, `TagController`, `ModerationController`, `AccessController`, `SignedUrlController`, `FollowController`, `NotificationController`, `UserController`, `ReportController`, `ActivityController`, `ProfileController`, `AdminController`, `AuthController`, `ConfigController`, `InterestsController`, `TransactionController` |
| `REST\` | Rate limiting middleware | `RateLimiter` |
| `Services\` | Business logic, storage, AI, caching, URL signing, variant pipeline, poster generation | `UploadService`, `StorageService`, `StorageRouter`, `MediaVariantWriter`, `VariantSpec`, `PosterService`, `PrivacyService`, `AlbumService`, `CollectionService`, `StoryService`, `AIService`, `OpenAIProvider`, `ModerationService`, `StatsService`, `AccessRulesService`, `SignedUrlService`, `WatermarkService`, `CacheService`, `LoggerService`, `GDPRService`, `HealthCheckService`, `ProfileService`, `LocalDriver` |
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
| `storage_router` | `StorageRouter` | 277 |
| `variant_writer` | `MediaVariantWriter` | 284 |
| `poster` | `PosterService` | 291 |
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

**37 services total.** Plus a non-container static helper: `Core\MediaUrl` (single read-side URL facade for non-REST callers; replaces the never-built `Services\MediaUrl` referenced before 1.5.0). `VariantSpec` is a value object (not container-registered) consumed by the upload pipeline.

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

1. **Max file size: 500 lines.** Files above this are tech debt (see Known Debt below). Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §1.
2. **Max method size: 50 lines.** Extract helpers or delegate to services. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §1.
3. **Database queries: always `$wpdb->prepare()`.** No raw interpolation.
4. **Admin HTML: template files only.** Never inline `echo` of HTML or `<script>` in PHP classes; use `templates/admin/`. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §2–§3.
5. **Hook names: `mvs_` prefix, snake_case.** Example: `mvs_media_uploaded`, `mvs_ai_providers`. Spec: `qa/rules/NAMING-RULES.md` §5.
6. **REST: extend `WP_REST_Controller`.** Every endpoint must define `get_item_schema()` and `get_item_permissions_check()` / `permission_callback`.
7. **Security: nonce + capability on every write.** Use `wp_verify_nonce()` for admin forms, `permission_callback` for REST.
8. **Error handling: `WP_Error` or `LoggerService`.** No silent `return false` — log failures. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §5.
9. **i18n: all user-facing strings wrapped.** Use `__()`, `esc_html__()`, `esc_attr__()` with text domain `wpmediaverse`. Spec: `qa/rules/NAMING-RULES.md` §10.
10. **Pro boundary: never import Free classes directly.** Pro hooks into `mvs_loaded` and uses `ServiceContainer` — no `use WPMediaVerse\...` in Pro code. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §9.
11. **No silent render fallthrough.** Every `return;` inside a render path (block `render.php`, shortcode callback, template, admin list, widget) must be paired with a visible empty state. Use `TemplateHelpers::render_block_empty_state()` / `render_admin_empty_state()`. Bare returns are only acceptable in hook callbacks, cron handlers, and REST permission checks. Spec: `qa/rules/RENDER-STATE-RULES.md`.
12. **CSS file ownership.** BP rules live in `bp-integration.css` (scoped under `#buddypress`). `frontend.css` is for generic plugin frontend only. Admin rules in `admin.css`. Messaging in `messaging.css`. Block-specific in `src/blocks/*/style.css`. Every BP-touching integration enqueues both `mvs-frontend` and `mvs-bp-integration`. No duplicate class-vs-ID rules. No `!important` without a one-line comment explaining what theme rule it fights. No dead selectors (every `.mvs-*` / `#mvs-*` must have an emitter). Spec: `qa/rules/CSS-ORGANIZATION-RULES.md`. Locked in `qa/inventory/WHAT-TO-CHECK.md` regression row "BP CSS file ownership".
13. **Names don't lie.** Class names, hook names, CSS classes must match actual usage. A `.mvs-bp-X` class used outside BP is a bug; either rename or narrow usage. Spec: `qa/rules/NAMING-RULES.md`.
14. **Sibling classes with ≥50% duplicate method bodies share a base class.** At n=2 duplication is tolerable; at n=3 it must be extracted. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §6.
15. **Debt tax.** No PR adds lines to files in the Known Debt table below. Every edit to a debt file must reduce its line count or extract code out, unless the PR body justifies the addition. Spec: `qa/rules/PROCESS-RULES.md` §3.
16. **Cache backend by cardinality.** Pick the storage by how many distinct keys a callsite produces, not by convenience. ≤ ~50 fixed keys (settings, global counters, feature flags) → `wp_options` or transients are fine. Anything keyed by `user_id` / `media_id` / `conversation_id` / any entity → custom `mvs_*` table. Never options. Never transients without a `wp_using_ext_object_cache()` guard. Per-request reuse → static array on the service class. Site-wide aggregate counts (total media, total views, storage size) MUST go through `Services\AdminAggregatesService` — raw `$wpdb->get_var` SUM/COUNT against `mvs_*` tables outside that service is a Rule 3 violation in `bin/coding-rules-check.sh`. Per-entity transients (key contains `$user_id` etc.) is a Rule 4 violation. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` (performance section).
17. **Never modify site navigation via code.** Creating pages on activation is fine (they back features), but detach `_wp_auto_add_pages_to_menu` around the `wp_insert_post` calls so menus with WP's auto_add option are never edited — see `Activator::create_pages()`. Hooking `wp_nav_menu_items` / calling `wp_update_nav_menu_item` to inject items is forbidden: a code-injected item can't be removed in Appearance → Menus and surprises every site running the plugin. Feature discoverability belongs in plugin-owned surfaces (dashboard tabs, banners), never the site's menus. (Varun, 2026-06-04, customer report.)
18. **Three entry points per data store — frontend, backend, API.** Every `mvs_*` table / data feature must be reachable through all three: (1) **frontend UX** that lets members use it, (2) **backend/admin UI** that lets the site owner see and manage it, (3) **REST API** that populates/reads it. A table created with a Migrator bump (even seeded) but missing any of the three is a HALF-COOKED feature — it ships dead weight a customer pays for but can never use. When adding a table or feature: wire all three in the same release, or document the intentional exception in the manifest (e.g., internal cache tables like `mvs_error_log` are admin+internal only). Every audit and every bug fix checks all three entry points — the bug class where a fix lands on one surface and misses the others (DM settings, Load More, members-privacy, all 2026-06) is this rule violated at fix time. **The API entry point is MANDATORY for MediaVerse, never an exception: a native mobile APP is planned, so every member-facing feature must be fully drivable through `mvs/v1` REST alone** — complete CRUD, auth that works outside the cookie/nonce browser context, consistent response shapes, honest pagination. A feature that only works through a PHP template or admin-ajax is app-blocking. (Varun, 2026-06-04.)
19. **No inline cosmetic CSS in markup.** Templates, block `render.php`, and HTML-echoing PHP must NOT carry cosmetic `style="…"` (color, background, margin/padding, font, border, box-shadow, text-align) or hardcoded hex. Move it to the owning stylesheet — block → `src/blocks/*/style.css`, BuddyPress → `bp-integration.css`, generic frontend → `frontend.css`, admin → `admin.css` — as a tokenized (`var(--mvs-*)`) class. **Reuse before you add:** most of these leaks were renderers re-inlining styles a tokenized class already provided (`.mvs-stat-value`, `.mvs-activity-audio-*`, `.mvs-leaderboard__*`); check the stylesheet first, never duplicate, never let markup and CSS diverge into dead selectors. **Allowed inline:** pure visibility toggles (`style="display:none"`), `var(--token, #fallback)` fallbacks, and instance custom properties (`style="--mvs-x:<?php … ?>"`). The CSS token-contract gate scans `.css` files only and is blind to inline `style=` — that is exactly why these slipped past WPCS + the CSS gate for so long. Enforced by `bin/template-style-check.sh` (local-CI stage 1.7). Spec: `qa/rules/CSS-ORGANIZATION-RULES.md`. (Varun, 2026-06-23.)

20. **A refusal is never a success response.** `wp_send_json_success()` must carry an outcome the caller asked for; a guard that declines the request returns `wp_send_json_error()` so the UI can say why. The demo importer refused ("demo data already exists") through a success envelope, so the admin JS took its success path and the Import button silently did nothing — no error, no data, no clue. Note what this means for tooling: every wiring layer was correct (button, JS binding, `wp_ajax_` handler, nonce), so a cross-layer action audit passed it cleanly. Wiring audits prove the plumbing connects, not that the logic behind it is right. Enforced by `bin/coding-rules-check.sh` Rule 6, which greps success responses for refusal language. (2026-08-01.)

**Process meta:** how rules are added, checked, and retired — `qa/rules/PROCESS-RULES.md`.

---

## Production Rules (50+ live sites — non-negotiable)

These rules protect 50+ production customer sites. They are mechanically enforced via `bin/architecture-checks.sh` where possible; the rest are review-time hard gates. **No exceptions in patch releases.**

1. **NEVER hard-remove a public symbol in the release it is deprecated.** Minimum 2 major versions between `@deprecated since X.Y.Z` and deletion. Applies to: functions, classes, methods, hooks (`do_action`/`apply_filters`), REST routes, AJAX actions, options, meta keys, capabilities, WP-CLI commands, service container keys.
2. **NEVER rename a public identifier without an alias.** Option keys, capability names, hook names, REST routes, service keys — add a new one and alias the old one for ≥2 major versions. The old read path must continue to work.
3. **NEVER ship a default-behavior change without a filter escape hatch.** Customer must be able to restore the old behavior via a one-line `add_filter` in a mu-plugin. The filter stays for ≥2 major versions before being removed.
4. **NEVER touch DB schema in a patch release.** Schema changes require `Migrator` version bump + minor release minimum. Migrations must be reversible OR include a documented one-way migration in the upgrade notes.
5. **NEVER remove a template file.** Templates can be overridden by themes via `locate_template`. Always alias the file with an `@deprecated` header for ≥2 major versions before deletion.
6. **NEVER bypass CI gates on release branches.** `SKIP_LOCAL_CI=1` and `--no-verify` are emergency-only AND must be documented in the commit message with a follow-up issue.
7. **Patch releases (1.2.x) are bug fixes only.** No behavior changes, no new features, no removals. If a fix requires a behavior change, it goes to the next minor release with a feature flag.
8. **Minor releases (1.x.0) are additive.** New features, new settings, deprecations. No removals. No breaking API changes.
9. **Major releases (x.0) are the only place removals happen.** Remove previously-deprecated symbols. Update the `@since` baseline in docs. Announce breaking changes 30 days ahead via release-notes channel.

**Every deprecated symbol must carry:**

- `@deprecated since X.Y.Z` PHPDoc on the declaration
- `_doing_it_wrong()` or `E_USER_DEPRECATED` trigger at runtime
- A documented migration path in the changelog (specific replacement)
- A planned removal version that is ≥ (X+2).0

**Cleanup PRs MUST use `plan/.template.cleanup.md`** and check candidate symbols against `audit/cleanup/bridges.json` before any deletion. Bypassing this template is itself a violation of rule #6.

---

## Known Debt (Do Not Worsen)

> **Debt criterion (2026-05-03 update):** A file lands here only when it has a CONCRETE structural problem — duplicate sibling classes, multiple unrelated responsibilities, a 350-line method, etc. Size alone is not a reason. For a plugin at WPMediaVerse's scale (34 services, 19 controllers, Free + Pro pair), files in the 1k–3k range are normal and healthy as long as they're focused on one responsibility. The team splits at ~2.5k+ when a file's scope genuinely outgrows one class (BP manager was 2,811; Settings was 2,401 — both already split).

| File | Issue | Status |
|------|-------|--------|
| `includes/Integrations/BuddyPress/` | (was 2,811-line manager mixing 7 unrelated BP integration concerns) | DONE — split into 7 focused classes |
| `includes/Admin/Settings/` | (was 2,401-line registrar with 7 settings groups + UI + sanitizers in one) | DONE — split into 5 classes (except `SettingsRegistrar.php`, see below) |
| `includes/Integrations/BuddyPress/ProfileTabIntegration.php` ↔ `GroupTabIntegration.php` | (was 80% duplicate method bodies between the two siblings) | DONE 2026-05-03 (Phase 5 P2.4) — `BaseBPTabIntegration` extracted; subclasses now hold only context-specific overrides. |
| `includes/Admin/Settings/SettingsRegistrar.php` | Consolidates the remaining settings groups (general+storage, display, moderation, webhooks, messaging, pages). The AI group was extracted to `AiSettingsRegistrar` (1.4.0, 1168→914 lines) — follow that pattern for the others when next touched. | OPEN (shrinking) |
| `includes/Services/UploadService.php` | (was 1,482 lines mixing 4 concerns: validation, type detection, storage routing, progress tracking) | PARTIAL — 1.5.0 extracted variant writes to `MediaVariantWriter` + storage routing to `StorageRouter` + poster generation to `PosterService` (1,482 → 1,211). Remaining: split `ValidatorService` + `ProgressTrackerService` when next touched. |
| `includes/REST/Controller/MediaController.php::replace_file` | 247-line method that orchestrates its own ingest instead of calling `UploadService::handle()`. Every step that can drift has already been pulled into a shared seam — `FilenameStrategy::pick()`, `apply_exif_orientation()`, `watermark->stamp_new_upload()`, `process_stored_file()` — so the two paths no longer duplicate *logic*; what remains is the orchestration shell, and it genuinely differs (replace UPDATES an existing row and must not reset stats or mint a new media_id, `handle()` CREATES one). | **ACCEPTED DEBT — deferred to 2.4.0** (decision 2026-08-06, Basecamp 10156642711). Collapsing the shell means teaching `handle()` an update mode: a behaviour change on the upload path, which Production Rule 7 forbids in a patch. Do it in the next minor with the replace-file journey green before and after. Debt tax applies now: no new inline ingest logic in this method — extract a seam and call it from both sides. |

**Files that are large but NOT debt** (mentioned because someone might wonder): `MessagingService.php` (1,596), `MessagingController.php` (803), `Plugin.php` (1,208), `MediaController.php` (1,105), `MediaRepository.php` (820). All are large because their domain is genuinely large, not because responsibilities are tangled. Don't propose splits unless a real organizational problem surfaces.

**Debt tax (Coding Rule #15):** No PR adds lines to a file in the OPEN rows above. Every edit must reduce the line count or extract code out. Files in the "large but NOT debt" list have no debt tax — edit them normally.

---

## Testing

| Key | Value |
|-----|-------|
| Framework | PHPUnit 9.6 + yoast/phpunit-polyfills 2.x |
| Test dir | `tests/unit/` |
| Test files | 42 as of 2026-08-11 (corrected — this line said 11 and named files, several of which no longer exist; run `ls tests/unit/*.php \| wc -l` rather than trusting a hardcoded count here again) |
| Coverage | Not precisely measured; grown substantially past the old ~10% estimate given the file-count growth above — re-measure with `phpunit --coverage-text` before quoting a number |
| Run | `./vendor/bin/phpunit` or `composer test:unit` (also stage 2.4 of local-CI, see below) |
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
| `dist/` | Release ZIP (generated) |
| `docs/` | Human-facing reference docs (see Doc Map below) |
| `qa/` | All pre-release QA — runbooks, rules, inventory, audits, runs (Free + Pro) |
| `audit/` | Machine-derived audits — manifests, reports, journeys, runs. Pro mirror: `audit/pro/` |
| `marketing/` | Marketing copy and assets |

---

## Doc Map

All docs (Free + Pro) live in this plugin. Pro is intentionally doc-free.

| Path | Contents |
|------|----------|
| `docs/architecture/MASTER_PLAN.md` | **Forward-looking** — current state + 6-month roadmap (Free + Pro). Trim shipped rows each release; do not add a "completed" section. |
| `docs/architecture/ARCHITECTURE.md` | Free architecture |
| `docs/architecture/architecture-contract.md` | Free ↔ Pro contract |
| `docs/architecture/pro/` | Pro architecture + Interactivity-API design |
| `docs/architecture/specs/` | Per-feature design specs (date-stamped) |
| `docs/development/` | CODING_STANDARDS, CONTRIBUTING, EXTENSION_GUIDE, GIT_WORKFLOW, LOCAL_TESTING, LOCAL_TESTING-pro, MOBILE_UX_GUIDELINE, REFACTORING_ROADMAP, STRUCTURAL_GUIDELINE |
| `docs/security/SECURITY_CHECKLIST.md` | Security checklist |
| `docs/verification/` | Ad-hoc verification reports (e.g. `cloud-storage-verification.md`) |
| `docs/website/` | Public docs site source (published to wbcomdesigns.com) |
| `docs/marketing/` | Marketing asset folder (different from `marketing/`) |

---

## QA

All QA lives in `qa/` — single home for Free + Pro. Pro has no `qa/` directory of its own.

| Path | Contents |
|------|----------|
| `qa/README.md` | Index — what's where and when to run |
| `qa/runbooks/AGENT_SMOKE_RUNBOOK.md` | Pre-release gate (release-blocking) — sections A–G |
| `qa/runbooks/MANUAL-UX-QA-free.md` | Free manual UX walkthrough |
| `qa/runbooks/MANUAL-UX-QA-pro.md` | Pro manual UX walkthrough |
| `qa/rules/` | Organization rules — CSS, NAMING, PHP, PROCESS, RENDER-STATE |
| `qa/inventory/WHAT-TO-CHECK.md` | Flat list — surfaces, actions, settings, data stores, contracts |
| `qa/audits/` | Dated audits (a11y, doc-drift, etc.) |
| `qa/runs/` | Append-only run evidence + `FINDINGS-HISTORY.md` + `drafts/` |
| `qa/.last-smoke-pass.json` | Release-gate green-light signal (combo mode) |
| `qa/.last-smoke-pass-free.json` | Release-gate green-light signal (free mode) |

At release: can the plugin demonstrate the things in `qa/inventory/WHAT-TO-CHECK.md`? Yes → ship. No → fix what's broken. The release gate (`bin/build-release.sh`) reads `qa/.last-smoke-pass*.json` and refuses to package without a fresh green pass.

---

## Recent Changes

_Last 1–2 releases only. Older history: `git log --oneline`._

| Date | Version | Summary |
|------|--------|---------|
| 2026-08-13 | 2.4.0 | **Runtime dependencies move to `libs/`; Composer becomes dev-only.** Adopts the BuddyNext/Learnomy pattern: hand-written PSR-4 autoloader in the entry file, runtime deps committed under `libs/`, `vendor/` gitignored and excluded from the zip. **Why, not tidiness:** `grunt dist` copied `vendor/` minus dev packages while `.distignore` excluded `/vendor` wholesale — and `.distignore` is what wp-plugin-qa, WordPress.org SVN tagging and third-party packagers read. A zip built that way had no autoloader: HTTP 500 on the front page, on `wp-admin/plugins.php`, and WP-CLI refusing to start, so the only recovery was FTP. **It also fixed a live behavioural bug:** `woocommerce/action-scheduler` was declared and shipped but never required, so `WebhookService`, `StorageCleanupService` and `StorageRepairService` sat behind `function_exists( 'as_*' )` guards that were always false on a Free-only site — webhooks sent synchronously inside the member's request, repair sweeps stopped mid-run. Bundled unscoped so version negotiation works. Knock-ons handled in the same commit: `uninstall.php` requires the migrator's file directly; the boot guard repointed off `vendor/autoload.php` (absent on every install under this layout, so it would have disabled the plugin for everyone); PHPCS and the duplication gate exclude `libs/`; PHPStan scans `libs/action-scheduler/functions.php`; `composer-prod`/`composer-restore` left the dist chain. `.distignore` also stopped excluding `seed-demo-data.php`/`cleanup-demo-data.php`, which `OverviewPage` requires for the Import Demo Data button. Verified with `vendor/` moved aside. `BootGuardTest` 5→9. Basecamp 10194740839 / 10194740942 / 10194741794. |
| 2026-08-13 | 2.4.0 | **A missing `vendor/` took the whole site down instead of the plugin.** The autoloader was wrapped in a bare `file_exists()` that skipped the require and let execution continue, so `plugins_loaded` called a class that did not exist. Replicated: front page 500, Plugins screen 500, WP-CLI refusing to start — every recovery route through WordPress closed. The guard now returns out of the entry file and registers an admin notice; same treatment for PHP version, since WordPress enforces the `Requires PHP` header only at activation and update time. `MVS_MIN_PHP` added and kept in step with the header by test. Basecamp 10194741899. |
| 2026-08-11 | 2.4.0 | **Documents gain a role gate and five real settings (paired with Pro 2.4.0; NOT released).** The Documents settings screen shipped with ONE checkbox because the plug-and-play directive was read as "everything else is a filter"; six decisions an owner genuinely makes were behind PHP. **New capability `use_mvs_documents`** (`Capabilities\MediaCapabilities::USE_DOCUMENTS_CAP`) answers "may this member have a document library at all" — distinct from `upload_mvs_media` (may they upload) and from privacy (may they read this file). **Granted to EVERY role by default**, including roles BuddyPress/WooCommerce register, via `get_base_member_caps()`; it is introduced on a shipped feature, so a default-denied cap would empty every existing drive on update. New `Plugin::user_can_use_documents( $user_id )` resolves toggle + capability + the new **`mvs_user_can_use_documents`** filter (the membership-tier seam; runs last so it widens as readily as it narrows). **uid 0 falls through to the site toggle** — gating logged-out visitors on a capability would break every anonymous share link and empty the public Explore Documents listing. **`MediaCapabilities::apply_role_caps()`** extracted as the ONE write path for a managed cap (live `WP_Role` + plural twin + `mvs_role_caps_overrides` so a version bump cannot undo a revocation); `PermissionsManager::process_role_caps_save()` now delegates to it, so the Permissions matrix and Pro's "Who can use documents" field are two views of one capability rather than two stores. **Verified as `journey-member`, never as admin:** unticking Subscriber removed the dashboard section and returned 403 `mvs_documents_unavailable` from `/documents`, `/folders` and `/me/shared`; re-ticking restored the drive with all 11 documents intact; a brand-new custom role received the cap from `add_caps()`; and a simulated update did not overturn the revocation. Certs 69/0/0 Free, 57/0/0 Pro (up from 51 — six new covered toggles). **Not run:** combo browser smoke. |
| 2026-08-10 | 2.4.0 | **My Media becomes a set of real sections; documents get an admin screen (paired with Pro 2.4.0; NOT released).** The dashboard renders from `DashboardSections` — one declaration per section, one renderer — as a grouped vertical rail with counts, and **every section is a URL** (`/my-media/<slug>/`) the server paints on first load. Editing your profile became a section (`/my-media/profile/`); the card above the tabs is gone, returning ~110px on every visit. **Documents admin gained a single view/edit** at `?page=mvs-documents&view=single&media_id=N` — title, slug, description, tags, privacy — writing through the SAME `set_many()` + `generate_unique_slug()` + `wp_set_object_terms()` the REST path uses, so the screen cannot drift from the API. Its guards: `is_document()` on both the view and the save (a photo id is refused, verified), an empty title refused rather than stored, and **the slug is never regenerated from the title** (a member fixing a typo would otherwise break every link they had shared). Row actions went from Trash/Delete-only to **Edit | View on site | Download | Trash | Delete**, and the Title now opens the editor rather than leaving for the front end. **Fixes, each found in a browser and each through green CI:** the Documents count beside a member's name was the site-wide total including trashed rows, not theirs; `$mvs_dash_drive` gated the Documents panel and **was never assigned in any revision**, so the gate always opened while warning on every dashboard load — the drive filter's `probe` branch existed for exactly this question and had never been asked; section URLs 404'd for anything declared through `mvs_dashboard_sections` because the rewrite alternation was a literal seven-slug list rather than the registry (`DashboardSections::slugs()` now supplies it); and the rail rendered as red underlined links on some themes because the plugin's OWN `!important` armour, written for the horizontal tab strip, outranked every rule the rail wrote for itself — the armour is now split by breakpoint. **API:** `drive_documents()` takes `status` (allowlisted publish/trash), `count_documents()` takes author + status scoping (default unchanged so the extraction health check still counts the site). **New filters:** `mvs_document_row_actions`, `mvs_document_admin_panels`. Plan: `plan/document-library.md` (the one document; §19 records what shipped). **Not run:** unit suite (WP test library absent), cert, combo smoke. |
| 2026-08-08 | 2.3.3 | **Album/collection IDs evicted from `mvs_media_index` (paired with Pro; NOT released).** `mvs_media_index.media_id` is `AUTO_INCREMENT` for media, but albums stored privacy by calling `MediaRepository::set()` with their **`wp_posts` ID** — two independent sequences in one primary key. On any site where uploads have outrun post IDs (most of them: members upload far more than a site publishes) an album write landed on a real photo and overwrote its slug and privacy. Reproduced live: creating an album returned post ID 82 and a photo already occupied index row 82. Three distinct harms, only one of which was previously known: **creating** an album overwrote a photo's slug+privacy (routine, silent); **adding media** to a colliding album read the *photo's* privacy and mis-scoped every item added, forever; **deleting** an album destroyed the photo's index row. Access control also inverted — `PrivacyService` resolved a colliding album to the photo's owner, so the album's owner was denied and a stranger granted. **The invariant now enforced: an album ID must never appear in `media_id`.** Album privacy/type live in post meta (`_mvs_privacy`, `_mvs_album_type`) behind `AlbumService::get/set_privacy()`; `MediaRepository::set()/set_many()` refuse a `wp_posts` ID via `_doing_it_wrong()` (memoised); both `purge_index_record()` calls **deleted** (Collection's was pure harm — collections never had a row, so it only ever hit someone else's photo); `AlbumController`'s album-list privacy gate rewritten onto post meta (**it is that endpoint's only privacy gate** — the old join would have listed every private album once albums stopped writing rows); `PrivacyService`'s `media_type` sniff from `3cfff321` removed as a workaround built on the bug. **Album categories removed** — write-only, since every browsing/filter/archive surface joins `mvs_category` to `mvs_media_index.media_id`; native metaboxes closed on both taxonomies (`meta_box_cb => false`), a live album-space write path no plugin code accounted for. **Migrator v26** (copy-then-delete, idempotent): lifts attributes to post meta, deletes attribute-only rows, **preserves and logs colliding rows** (`mvs_cpt_id_collisions`), clears album-space term rows only where the ID is not also media. **Pro**: 11 album-ID writes moved off the index across MediaPress/rtMedia/BuddyBoss; `AlbumMarkerLookupTrait` extracted — and the marker *read* moved with the write, or every importer re-run would have duplicated albums (the historic bug, mirrored). New read-only `wp mvs diagnose_cpt_ids` reports collisions, data-loss risk, overwritten slugs and a forecast of how likely the next album is to corrupt something. **Not verified:** no browser testing; the 6 regression tests have never run (WP test library absent — whole suite fails on `AccessRulesServiceTest`). **Breaking:** `categories` removed from album REST responses — grep BuddyNext + the app before release. **Unrecoverable:** overwrites whose album was later deleted are undetectable, original slugs are gone, and a privacy overwrite cannot be told from a deliberate change. Basecamp 10183850886 / 10183851181. Plan: `plan/2026-08-08-cpt-id-collision-fix-plan.md`. |
| 2026-08-05 | 2.3.1 | **CDN image fix + validation sweep (paired with Pro 2.3.1).** (1) **WebP/AVIF 403 behind a CDN** (Basecamp 10162798416, customer vystopiatransformation.com) — the 1.4.0 driver-agnostic refactor moved the JPEG onto a relative `thumb_<size>_path` resolved through `get_driver_for_location()`, but left WebP/AVIF reading the absolute `thumb_<size>_webp` URL frozen at process time. Its only guard was a `/mvs/v1/serve` substring test, and a direct CDN URL contains no `/serve`, so every variant went out pointing into `wp-content/uploads/wpmediaverse/` — the directory the plugin itself locks with `Deny from all`. New `Core\MediaUrl::variant_url()` is the single resolver (`_path` via the location driver, legacy URL as fallback for imported rows that never got one); `TemplateHelpers`' two near-identical variant methods collapsed into one that also enforces the invariant **never emit a variant on a different host from its primary**. `CloudOps::migrate_one()` now repoints every `%_path` sibling URL meta after a migration. Verified end-to-end against a real BunnyCDN zone. (2) **Blank media titles** — `update_item()` guarded on `null !== $title`, so `''` passed straight through; a member could leave an item unnamed and any REST client could too. Now 400 `mvs_empty_title` plus `minLength` on the route arg (the schema catches `''`, the controller's `trim()` catches whitespace-only), and both edit modals disable Save with an inline hint. (3) **Storage panel arithmetic** — `on_cloud` was derived as `total - needs_migration`, but `total` spans every privacy level while `needs_migration` is public-only, so private rows were counted as "in cloud" *and* as "private media (kept here)". Counted from the index instead; tiles now sum to the library total. (4) **Coding Rule 11** — `[mvs_album]` and `[mvs_player]` rendered nothing on a bad id; they now name the problem for editors and stay silent for visitors, with privacy branches deliberately still silent. (5) **a11y** — reaction buttons on media-single gained `aria-pressed` + a group label, matching the lightbox. Two QA docs were also found to assert the opposite of the code and were corrected (private-media activity rows, signed-URL no-token status). (6) **CLI migrate left every variant behind** — `Commands::migrate_storage` carried its own transfer loop that moved `file_path` alone, so on a CDN migration the thumbnails and WebP/AVIF siblings stayed on local disk while `get_driver_for_location()` resolved their URLs to the CDN: 404 on every derived image, grid and single view both broken. It now delegates per row to `CloudOps::migrate_one()` (the same implementation the Pro admin "Migrate all" button always used, which is why the admin path was never affected) — 84 lines of duplicate transfer logic deleted. `cloud-thumbs-backfill` gained `sweep_stored_variants()` so it also pushes `original_webp` / `original_avif` (not size variants, so `generate_thumbnails` never owned them) and, critically, still sweeps when the `thumb_*` metas are already on cloud — that skip branch was what left already-migrated sites permanently broken on the single-media view. Reproduced and verified end-to-end against a live BunnyCDN zone. Journey `08-storage-switch-migrate-mobile` step 5b now covers the CLI path; the journey was UI-only, which is how this shipped. 284 unit tests, combo smoke green. |
| 2026-07-24 | 2.2.1 | **QA-card sweep release (paired with Pro 2.2.1; Bugs + RFT columns cleared to 0).** (1) **Single-thread conversation delete** - `leave_conversation()` now records a per-user clear watermark (`participants.cleared_up_to` = highest message id at delete, Migrator v24) and `find_or_create_conversation()` always reuses the pair's direct thread, so the other member never sees a duplicate. All user-reachable read paths (`get_messages`, `search_messages`, `get_conversation_media`, both unread counters, `poll`, `poll_reaction_updates`, list previews via `suppress_cleared_preview()`) filter `m.id > cleared_up_to`. Watermark is an id, not a datetime - same-second messages survive (`MessagingClearedThreadTest`, 5 tests). (2) **Typed attachment-only previews** - `build_message_preview()` takes attachment/media ids: localized Photo/Video/Audio/File labels, MIME-resolved for attachments under `message_type=text`, `media_type`-resolved for BuddyNext-style media shares; mirrored in `messagePreview()` + the optimistic send path in `messaging.js`; labels seeded via the messaging i18n map. (3) **Message poll cursor sweep** (PR #13) + **BuddyBoss `bp_media_ids` array fatal** (PR #14). (4) **Dark-mode BP buttons** - bp-integration.css: bare `#mvs-bp-upload-btn`/`#mvs-bp-create-album-btn` styled as primaries, `--mvs-bg`→`--mvs-on-primary` on `.mvs-btn-primary`, secondary hover contrast, `#buddypress`-scoped BEM variants. (5) **Album double share icon** - 🔗 emoji dropped from album.php shareLabel + `shareResetLabel` + view.js fallback. (6) **Placeholder-less `wpdb::prepare()` notice** on the anonymous activity feed COUNT fixed. `MessagingReactionUpdatesTest` cursors corrected to model a real client. Certs 68/0/0 + 39/0/0, combo smoke green, 268 unit tests. |
| 2026-07-24 | 2.2.0 | **Privacy gate + messaging/UX fix release (paired with Pro 2.2.0).** (1) **`REST\CommunityPrivacyGate`** (new) — `rest_pre_dispatch` gate over the whole `mvs/v1` surface; armed via filter `mvs_rest_require_auth` (BuddyNext arms it in private-community mode), access decided by `mvs_rest_can_access` (default `is_user_logged_in()`), blocked callers get `401 mvs_community_private`. Unit-tested (`CommunityPrivacyGateTest`). (2) **DM attachments media-only** — `application/pdf` removed from the `/messages/upload` allowlist (`mvs_dm_allowed_file_types` remains the per-site escape hatch); client picker narrowed to `image/*,video/*,audio/*` with a type-guard toast. Composer gained an uploading chip (`uploadingAttachment` state + `attachmentChipLabel` getter in `messaging.js`); `canSend` is false while an upload is in flight (closes the send-without-attachment race); cancel-mid-flight invalidated via the `attachmentRequestId` generation counter. (3) **Live reactions on delivered messages** (`MessagingService`, `MessagingReactionUpdatesTest`). (4) **Follow counts update live** — `profile-actions.js` now targets `.mvs-follows-open[data-list] strong` (free explore header) with a span-index fallback (Pro layout headers); previously `spans[1]` matched nothing and the display froze. (5) **Settings-saved notice** — dismiss button, 5s auto-dismiss of all per-section copies, `settings-updated`/`permissions-saved` stripped from the URL in `settings-nav.js`. (6) **Lightbox favorite active color** — the theme-defense `!important` color pin beat the un-important `.active` rule; active variant added inside the guard + heart SVG fill. (7) **BuddyBoss/older-BP fatal** — `bp_get_group_url()` guarded. Contract audit: 3 AS/WP-Cron constant-fired hooks baselined, 0 errors/18 baselined. Combo browser smoke green (`qa/.last-smoke-pass.json`, 0 failures / 0 debug-log issues). 2.0.0/2.1.0 history: `git log`. |
| 2026-06-14 | 1.7.0-dev | **Media grid N+1 fix (perf Phase A).** Server-rendered grids did ~14 DB queries/tile (170 for a 12-tile page), dominating PHP render time on shared hosting. Now **~6 queries/page** (-96%, 14.2ms→5.8ms). Changes: `MediaRepository::prefetch()` + new `AccessRulesService::prefetch_active_rules()` called before every grid loop (`explore.php`, `album.php`, `collection.php`, `media-grid` + `explore-feed` render.php); `get_raw()` Tier 1b honors `$meta_fully_loaded` (absent-key reads after prefetch skip the query); `get_all()` + `exists()` are cache-aware; `AccessRulesService::has_active_rules()` request-cached (flushed on rule writes); `filter_privacy_can_view()` reordered to check cached `has_active_rules()` first and skip the per-tile `get_post()` owner lookup for rule-less media (safe — `PrivacyService::check_access()` grants owner/admin before the filter). Access-control re-verified (public/private/owner/admin/access-rule). Journey 15 added. Plan: `docs/architecture/specs/2026-06-14-media-delivery-performance-rework.md` (Phase B = optimize /serve + page/object cache, no storage restructure; static-serve rework recorded but not chosen). |
| 2026-06-14 | 1.7.0-dev | **Bug-card sprint (5 cards, all Free; Pro inherits).** (1) **Categories silent-drop** - `MediaController::update_item()` re-read freshly-written terms via `get_the_terms()` and a transient persistent-object-cache miss took a destructive else-branch that wiped the `category` meta to `[]` (HTTP 200, not applied). Now derives the cached names from the submitted term IDs; only an empty array clears. The EDITABLE `/media/{id}` route now declares its real `args` (categories/tags/title/description/slug/privacy/allow_download) - was `id` only. (2) **Oversized grid thumbnails** - grids hardcoded the 1024px `large` and never called `SettingsHelper::get_thumbnail_size()`. New `SettingsHelper::get_grid_thumb_size_key()` routes every grid/feed URL (MediaController, FavoriteController, media-grid + explore-feed render.php, `TemplateHelpers::media_thumbnail`/`render_grid_thumbnail`/`render_grid_item`) through the configured size; **default flipped `large`→`medium`**. (srcset was prototyped then reverted - it added 3 signed-URL gens/tile, worsening the render N+1 on shared hosting; deferred to the static-serve perf rework.) (3) **Blank video tiles** - REST `thumbnail_url` for posterless videos was `''`; now falls back to the bundled default poster SVG. New Site Health test `wpmediaverse_video_posters` + `PosterService::is_ffmpeg_available()` surface missing ffmpeg. (4) **Public media uncacheable on local driver** - `SignedUrlService` now gives PUBLIC media a render-stable signed URL (`resolve_expiry()`, filter `mvs_stable_public_urls`) + `Cache-Control: public, max-age` (filter `mvs_public_media_max_age`, default 1 week) in `serve()`/`serve_thumbnail()`; private stays no-store. New local-driver public-URL hooks `mvs_public_local_file_url` / `mvs_public_local_thumbnail_url`. (5) **BuddyNext notification contract** - `mvs_notification_created` now appends `$message` + `$link` (from shared `NotificationService::build_message_and_link()`, reused by `format_notification()`), backward-compatible. **New filters:** `mvs_stable_public_urls`, `mvs_public_media_max_age`, `mvs_public_local_file_url`, `mvs_public_local_thumbnail_url`. **Setting default change:** `mvs_thumbnail_size` `large`→`medium`. Journeys 11-14 added. CI green (lint/WPCS/PHPStan/coding-rules/contract). Manifest full refresh pending at release. No DB schema change. |
| 2026-05-28 | 1.5.0-dev | **Upload + serve pipeline unification.** (1) **Bug fix** - non-public thumb 403 (Basecamp #9925110293) was three sibling defects sharing one cause: 5+ places in the upload code computed "rel path for variant X" with slightly different rules. Fixed at `SignedUrlService::serve()` (privacy gate now prefers live session uid, falls back to HMAC-signed `mvs_uid`) and `UploadService::generate_thumbnails()` (`thumb_*_path` derived from the variant's actual on-disk dir). (2) **New services** - `Services\VariantSpec` (canonical rel-path computation + meta-key derivation, single source of truth), `Services\StorageRouter` (driver routing collapses 3 inline cloud-eligibility checks), `Services\MediaVariantWriter` (single owner of every `thumb_*` / `_path` / `_webp` / `_avif` meta write - 14 scattered `set()` calls collapsed to one `record()`), `Services\PosterService` (sole owner of `wpmediaverse/posters/` writes: video Path A getID3, Path B ffmpeg, client-frame staging, audio ID3 art), `Core\MediaUrl` (read-side facade promised in CLAUDE.md since 1.4.0 - finally exists). (3) **Refactor** - `UploadService.php` 1482 → 1211 lines, exits Known Debt. `ImageOptimizationService.php` collapses `publish_webp_to_active_driver` + `publish_avif_to_active_driver` twins to one extension-parametrized method. `TemplateHelpers::get_thumb_url()` is now a one-line delegate to `MediaUrl::thumb()`. 5 direct callers of `SignedUrlService` migrated to `MediaUrl` (explore.php, media-single.php, Shortcodes.php, CollectionController.php, lock-overlay/render.php). (4) **Migrator v15** - heals legacy video/audio `thumb_<size>_path` rows where pre-1.5.0 writes pointed at `2026/05/<id>-WxH.jpg` instead of `posters/<id>-WxH.jpg`. Idempotent, scoped to video/audio, includes `posters/<basename>` probe fallback for sites whose URL meta also diverged. (5) **MediaUrl uid=0 cleanup** - dropped the dead `>= MONTH_IN_SECONDS` TTL heuristic (WMV-05 cut broadcast TTL to 1h); now any uid=0 mint forces privacy check. Compat: same REST routes, same hooks, same filters, same meta keys (legacy URL meta still written alongside `_path` per Production Rule #1). No DB schema change. |
| 2026-05-26 | 1.4.0 | **Release-prep hardening pass.** (1) **Destructive-element security** — every admin row-action and bulk handler in `MediaListPage`, `ModerationQueue`, `TagManagementPage` now pairs `current_user_can()` inline next to its `check_admin_referer()` / `wp_verify_nonce()`. WordPress.org Plugin Check + wp-plugin-qa security audit both clean on customer-shipping files. (2) **No more native confirm/alert** — `assets/js/admin/{confirm-links,media-list,overview}.js` and `assets/js/frontend/{bp-actions,mvs-confirm}.js` had `window.confirm` / `window.alert` fallbacks removed. `mvsConfirm` and `mvsToast` are now hard dependencies; destructive flows fail closed when those helpers are missing. (3) **Driver-agnostic media URLs** — completes the location-based-display refactor: every read goes through `StorageService::get_driver_for_media($id)` so URL shape flips with the active driver and zero DB writes happen on toggle. Verified across local / bunnycdn / r2. (4) **WP-CLI** — `wp mvs relocalize-private` heals legacy non-public rows that still pointed at a prior cloud bucket. Idempotent. (5) **Migrator v14** backfills driver-agnostic `_path` meta for the full corpus (100% image coverage). (6) **Bug fixes** — BP composer privacy leak (#9867136209), non-public thumb 403 (#9925110293), bulk album N activities (#9847529154), Safari/Bing video poster (#9910574354) all verified end-to-end. (7) **Build hygiene** — `bin/build-release.sh` now wipes every prior-version dist ZIP before re-building so `dist/` only ever carries the current version. Paired with Pro 1.4.0. |
| 2026-05-21 | 1.4.0-dev | **Storage robustness + AI control + i18n/mobile QA.** (1) **Location-based media display** — `SignedUrlService` serves each media from its actual stored location + privacy, not the active driver or a toggle; public-on-cloud serves direct from the CDN, local via `/serve`. Fixes site-wide broken media when switching/enabling a cloud service. The legacy `mvs_cloud_direct_public_urls` checkbox was retired (option kept). Filters: `mvs_serve_public_cloud_direct`, `mvs_public_cloud_thumbnail_url`, `mvs_public_cloud_file_url`. (2) **Private stays local** — `StorageService::get_driver_for_privacy()`/`get_driver_for_media()`: only public media is cloud-eligible; private/restricted uploads + thumbnails + variants never leave local disk (UploadService + ImageOptimizationService routed through it). (3) **Non-public `/serve` re-checks `can_view` per request** — closes the signed-URL-as-bearer-token gap for private media. (4) **Raw R2 host never emitted** — `is_cloud_hosted_url()` declines `*.r2.cloudflarestorage.com` (never public) → local fallback. (5) **AI** — fixed the `mvs_ai_provider` `google`→`google_vision` mismatch (was silently using OpenAI); budget cap now also gates moderation; new per-feature toggles `mvs_ai_auto_describe` / `mvs_ai_auto_tag`; AI settings extracted to `AiSettingsRegistrar`. (6) **Moderation** — per-row Approve/Reject were dead (nested forms); restructured with HTML5 `form=` association. (7) **Upload block** — fixed malformed Pro quota-check URL. (8) **i18n** — chat/messaging templates wrapped, `bp-activity-media.js` bridged via `wp_set_script_translations`, both `.pot` regenerated. (9) **Mobile** — Explore tag chips, upload privacy select, lightbox close + gallery nav all raised to the 44px touch floor; zero horizontal overflow at 390px. (10) **Journeys** — framework gained desktop+mobile+i18n+privacy dimensions; new journeys (explore-browse-mobile, storage-switch-migrate-mobile, private-media-local-and-gated, ai-features-owner-control); full 17-journey suite run (15 PASS, 2 FAIL both fixed). Paired with Pro 1.4.0. |
| 2026-05-17 | 1.3.0 | **Image optimization pipeline + WebP/AVIF + cloud storage tools.** Bundles all work from the unreleased 1.2.1 and 1.2.2 branches into a single release. Headlines: (1) **Image optimization pipeline** — `Services\ImageOptimizationService` with single `mvs_optimize_image` filter (EWWW/Imagify/Smush/ShortPixel extension point), lossless re-encode of JPEG/PNG/GIF with temp-write-compare-commit guard (never inflates), animated GIF detection, default-on setting. (2) **WebP + AVIF siblings** — WebP at upload time, AVIF opt-in (slower encoding), browser-native `<picture>` serving across explore grid + BP activity + dashboard + single-media + lightbox via `TemplateHelpers::picture_or_img()` and the Interactivity-API lightbox getters. Size-compare guards on every variant. (3) **/serve Accept-header negotiation** — gated `/serve` route now negotiates WebP/AVIF via the client's `Accept` header for private media; `Vary: Accept` set. (4) **Cloud storage tools** — `Services\CloudOps`, `StorageDriverInterface::download()` contract, 3 CLI subcommands (`migrate-storage`, `cloud-thumbs-backfill`, `cleanup-local`), opt-in `mvs_cloud_direct_public_urls` setting for CDN bypass. (5) **Video poster** — Path A getID3 cover atom + Path B ffmpeg fallback via `proc_open` with auto-detect resolver (`/opt/homebrew/bin`, `/usr/local/bin`, `/usr/bin`, `/opt/ffmpeg/bin`) for PHP-FPM PATH differences. Default video poster SVG for the rare all-paths-failed case (render-time only, never written to meta, never cloud-uploaded). (6) **Audio waveform** — embedded ID3/FLAC art → image card; cover-less audio → deterministic 48-bar SoundCloud-style SVG. (7) **WP-CLI** — `wp mvs optimize <id>`, `wp mvs optimize-bulk`, all resume-safe via `_mvs_optimized_at` sentinel. (8) **Admin** — Optimization column on All Media (-X.X% / WebP ready / No lossless gain badges), Optimize + Details row actions, Details mini-page at `?view=details`. (9) **Security** — BP activity privacy follows media privacy (Zoho #39974); REST per_page hardening across all list endpoints. (10) **100k-readiness** — `Services\AdminAggregatesService`, FULLTEXT search, view-retention cron, `MediaRepository` request cache + `prefetch()`. (11) **MVS_CSS consolidation** — Pro now consumes Free's `\WPMediaVerse\Blocks\MVS_CSS` directly (Pro's duplicate class deleted, -276 lines). (12) **M1 + M2 data-access cleanup** — 4 Free + 5 Pro template `$wpdb` call sites refactored through MediaRepository helpers; 9 Pro `includes/` files routing through Free services via `Plugin::free_service()`. (13) **Settings UX fix (QA-found)** — `FieldRenderer::render_checkbox_field` now honors `register_setting` defaults instead of hardcoded false; was about to silently disable image optimization on first admin Save. **Settings added:** `mvs_optimize_originals`, `mvs_generate_webp` (default on), `mvs_generate_avif` (default off), `mvs_telemetry_enabled` (default off). **Meta keys added:** `original_webp`, `original_avif`, `thumb_<size>_webp`, `thumb_<size>_avif`, `_mvs_optimized_at`, `_mvs_bytes_before`, `_mvs_bytes_after`, `mvs_media_meta.original_filename`. **Filters added:** `mvs_optimize_image`, `mvs_optimize_jpeg_quality` (92), `mvs_webp_quality` (82), `mvs_avif_quality` (50), `mvs_ffmpeg_binary`, `mvs_default_video_poster_url`. **Action hooks added:** `mvs_media_privacy_changed`. |
| 2026-05-12 | 1.2.0 | "Complete the experience" release — Member Photos block + PDF Viewer block + Media Grid sort options + Explore search autocomplete + Lightbox Download + per-media Edit modal + OG/Twitter meta on `/media/{slug}/` + upload modal UX polish + Bulk Actions on All Media + chat panel visibility setting + global allow-downloads toggle + WCAG 2.1 AA pass across all customer-facing surfaces + 9 Free blocks standardized on Spacing/Border/Shadow/Visibility panels matching Pro. New: `Core\SettingsHelper`, `SettingsContractTest`, `BaseBPTabIntegration`. Fix: BP notification dedup. |

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
| 1.4 CSS token-contract | `bin/css-token-contract.sh` | phantom `var(--mvs-*)` tokens + dead dark-mode selectors | ✅ exits 0 |
| 1.5 Duplication gate | `bin/duplication-gate.sh` | new copy-pasted method bodies vs the frozen baseline | ✅ exits 0 |
| 1.6 Journey coverage | `bin/journey-coverage.sh` | release-critical features missing an executable journey | ✅ exits 0 |
| 1.7 Template-style | `bin/template-style-check.sh` | inline cosmetic CSS / hardcoded hex in markup (Coding Rule 19) | ✅ exits 0 |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific rules (Rules 1-6, plus Rule 7: no direct `mvs_media_index` query outside `MediaRepository`) | ✅ Rule 2's allowlist is populated (0 callsites outside it — the "23 callsites" note here was stale, corrected 2026-08-11). ⚠️ Rule 7 added 2026-08-11: 24 files / 66 call sites known and tracked (`plan/document-library.md` §24.2 item 1), currently `known_gap()` (visible, non-blocking) until the incremental migration clears the list — then it becomes a hard `violation()`. |
| 2.2 (Pro only) | `bin/architecture-checks.sh` | Free/Pro contract invariants | (Pro only) |
| 2.3 Settings contract | `composer test:contract` | register_setting whitelist alignment (catches the d986525 bug class) | ✅ exits 0 |
| 2.4 Full unit suite | `composer test:unit` | Every PHPUnit test in the plugin | ⚠️ Added 2026-08-11, mirroring the same gap closed on Pro the same day (Basecamp #10184313297). **Rare, unreproduced flake**: `CptIdCollisionTest.php` showed 2 order-dependent failures ("Linkage C") on 1 of 8 full-suite runs measured 2026-08-11; the other 7 were fully green. Confirmed NOT the same root cause as Pro's fix (`MediaRepository::reset_test_cache()`) — no Free test file truncates `mvs_media_index`, so that mechanism cannot apply here. Targeted repro attempts (isolated file, the alphabetically-preceding test files run together) did not reproduce it either — whatever triggers it needs a wider or different net than tried so far. **No speculative fix has been applied** — a guessed fix with a ~1-in-8 failure signal to validate against would be indistinguishable from dead code. Left open and documented rather than papered over; see `plan/2026-08-11-pro-competitions-test-triage-plan.md`'s closing note for the pointer. |
| 3.1 Manifest | `jq` on `audit/manifests/manifest.json` | manifest validity + freshness | ✅ exits 0 |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end | (skipped in `:no-journeys` mode) |

**Composer scripts (added 2026-05-01):**
- `composer phpcs` — runs WPCS errors only (`--warning-severity=0`); exits 0 on baseline.
- `composer phpcs:full` — runs WPCS with warnings shown (informational; still exits 0 if no errors).
- `composer phpcs:fix` — runs phpcbf to auto-fix fixable violations.
- `composer phpstan` — runs PHPStan with the existing baseline; exits 0.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

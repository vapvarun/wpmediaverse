# WPMediaVerse — AI Quick Reference

> **READ FIRST:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) is a ≤2 KB index — load it first. The full inventory in [`audit/manifests/manifest.json`](audit/manifests/manifest.json) (v2.2 schema) covers **53 REST endpoints, 3 plugin AJAX actions, 7 admin pages, 35 settings, 24 hooks fired (7 with Pro consumers + 4 new public filters in 1.2.2), 21 tables, 13 blocks, 29 services, 3 WP-CLI subcommands**. Detail files: [`manifest.rest.json`](audit/manifests/manifest.rest.json), [`manifest.hooks.json`](audit/manifests/manifest.hooks.json), [`manifest.tables.json`](audit/manifests/manifest.tables.json). Cross-plugin coupling: [`audit/derived/cross-plugin-coupling.json`](audit/derived/cross-plugin-coupling.json). Bug-finder baseline: [`audit/runs/2026-05-03-wppqa-baseline-SUMMARY.md`](audit/runs/2026-05-03-wppqa-baseline-SUMMARY.md). Reports: [`audit/reports/FEATURE_AUDIT.md`](audit/reports/FEATURE_AUDIT.md), [`audit/reports/CODE_FLOWS.md`](audit/reports/CODE_FLOWS.md), [`audit/reports/ROLE_MATRIX.md`](audit/reports/ROLE_MATRIX.md), [`audit/graph.html`](audit/graph.html). Pro audit mirror: [`audit/pro/`](audit/pro/). Refresh: `/wp-plugin-onboard --refresh`.

## Quick Facts

| Key | Value |
|-----|-------|
| Version | 1.3.0 |
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
| 2026-05-17 | 1.2.2 | **Image optimization pipeline + customer-friendly admin** — new `Services\ImageOptimizationService` with single `mvs_optimize_image` filter (extension point for EWWW/Imagify/Smush/ShortPixel; no per-plugin adapters in core). Lossless re-encode of JPEG/PNG/GIF with temp-write-compare-commit guard (never inflates source). WebP siblings emitted alongside original + every thumbnail size; only kept when strictly smaller than source; pushed to active cloud driver and locals deleted on cloud installs (server disk stays flat). Frontend `<picture>` serving via new `TemplateHelpers::picture_or_img()` — covers explore grid, BP activity feed, dashboard, single-media view; deferred for lightbox (needs Interactivity state binding, 1.3.0). New WP-CLI: `wp mvs optimize <id>` + `wp mvs optimize-bulk` with resume-safe `_mvs_optimized_at` sentinel. Admin: new Optimization column on All Media (`-X.X%` / `WebP ready` / `No lossless gain` badges), Optimize + Details row actions, read-only Details mini-page at `?view=details`. **Video poster ffmpeg fallback** — `generate_video_poster_thumbnails()` Path B extracts first frame via `proc_open` (no shell injection surface) when video has no embedded cover atom; runs through the same optimize+webp pipeline. **Audio cover art + waveform fallback** — embedded ID3/FLAC album art runs through `generate_thumbnails()` becoming a normal image card; audio without art renders a deterministic 48-bar SoundCloud-style waveform SVG seeded by media_id (`render_audio_waveform_svg`). **Cloud-driver thumbnail fix** — `process_upload()` now ensures a local source copy at the canonical uploads path before metadata extraction, so cloud-driver uploads no longer end up with no thumbnails (pre-existing bug). **Explore feed chronological** — albums removed (static containers misrepresented freshness); feed is now media-only strict `created_at DESC`. **Settings added:** `mvs_optimize_originals`, `mvs_generate_webp` (Storage tab, both default on). **Meta keys added:** `original_webp`, `thumb_<size>_webp`, `_mvs_optimized_at`, `_mvs_optimize_failed`, `_mvs_bytes_before`, `_mvs_bytes_after`. **Filters added:** `mvs_optimize_jpeg_quality` (default 92), `mvs_webp_quality` (default 82), `mvs_ffmpeg_binary` (default 'ffmpeg'). |
| 2026-05-07 | 1.2.1 | **Cloud-storage tooling end-to-end** — new `Services\CloudOps`, `StorageDriverInterface::download()` contract, 3 CLI subcommands (`migrate-storage`, `cloud-thumbs-backfill`, `cleanup-local`), cloud-side thumbnail variants pushed at upload time, opt-in `mvs_cloud_direct_public_urls` setting, `mvs_cloud_thumbnail_url` filter for CDN-side resize. **Privacy gate:** cloud upload paths filter `WHERE privacy='public'` — non-public media stays local until cloud-aware /serve in 1.3.0; `mvs_cloudops_allow_non_public_to_cloud` for private-bucket overrides. **i18n sweep:** 98 strings wrapped (chat templates, frontend JS, Interactivity API view scripts via `window.wp.i18n.__` runtime shim — script modules can't import `@wordpress/i18n` yet); locale-broken `textContent.indexOf` matchers in `bp-activity-media.js` rewritten to data-attribute selectors; `.pot` regenerated 1 → 1,179 entries. **Customer fixes:** Phase 1A BP activity privacy sync (Zoho #39974), `shared-ui-shell.css` → `shared-ui-frame.css` rename (Crisp #NZRSBX). **100k-readiness:** `Services\AdminAggregatesService`, FULLTEXT search, view-retention cron, `MediaRepository` request cache + `prefetch()`, REST `per_page` hardening. **Filename strategy:** `mvs_filename_strategy` setting (hashed/original_sanitized) + `mvs_media_meta.original_filename` for display. |

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

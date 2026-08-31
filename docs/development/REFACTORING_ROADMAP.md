# WPMediaVerse Refactoring Roadmap

> **THIS IS A PLAN, NOT A DESCRIPTION OF THE CODE.** Everything below that is not
> explicitly marked ✅ DONE is **PLANNED / NOT BUILT** - the "Current state" and
> "Target state" blocks describe an intention, not shipped behaviour, and the
> class names, file paths, and line counts in them were written at planning time
> and have not been kept in sync with the code. Do not cite this file as evidence
> that anything exists.
>
> For what the plugin actually is right now: `CLAUDE.md` (current state + Known
> Debt table) and `audit/manifests/manifest.summary.json` (code-verified counts).
> Where a ✅ DONE item's target class names differ from what actually shipped,
> the code wins - the split happened, the naming was decided during the work.

This is the prioritized structural refactoring backlog for the WPMediaVerse free and pro plugins. These are architectural changes - not style fixes - that reduce coupling, improve testability, and make the codebase maintainable as the feature set grows.

## Status

P1 items are **all done** (in 1.1.x). P2 and P3 items are **not built** - they remain a backlog, not a commitment to a version. The 1.2.0 milestone plan they were originally scheduled against no longer exists; current per-topic plans live in `plan/` (plugin root) and `docs/plans/`. Per-item status is recorded inline below; anything without a ✅ has not shipped.

---

## Priority 1 - Foundation (all ✅ DONE)

### 1.1 Extract MediaRepository - ✅ DONE

**Current state**
~842 `$wpdb` calls are scattered across 30+ files. There is no single place that owns media data access; SQL is duplicated, inconsistently escaped, and impossible to mock in tests.

**Target state**
`includes/Repository/MediaRepository.php` - a single class that owns every media query.

Method names proposed at planning time (some shipped under different names -
read `includes/Repository/MediaRepositoryInterface.php` for the real surface):
- `get_by_id( int $id ): ?array` → shipped as `get_all( int $media_id ): array` (plus `get()` / `get_raw()` for a single column)
- `get_by_slug( string $slug ): ?array` → shipped
- `get_published_count( array $args = [] ): int` → shipped as `count_published()` / `count_by_author()` / `count_by_group()`
- `get_moderation_counts(): array` → shipped
- `get_by_author( int $user_id, array $args = [] ): array` → the read side is `count_by_author()` / `count_visible_by_author()` plus the generic query methods

All existing callers across free and pro must be updated to use the repository. No raw `$wpdb` calls for media data outside this class.

**Definition of Done**
All media queries route through `MediaRepository`; all call sites updated; existing behavior unchanged; class is injectable (no static methods).

**Why this matters**
Untestable data access spread across 30+ files is the single biggest barrier to meaningful test coverage.

---

### 1.2 Split BuddyPressIntegration.php - ✅ DONE

**Current state**
`includes/Integrations/BuddyPressIntegration.php` - 2,811 lines handling activity sync, notifications, profile tabs, group tabs, and form handling in one class. Six concerns, one file.

**Target state**
`includes/Integrations/BuddyPress/` directory with five focused classes:

| Class | Responsibility | Approx. size |
|---|---|---|
| `ActivitySyncService.php` | One-way comment and activity sync | ~500 lines |
| `NotificationBridge.php` | BP notification dispatch | ~300 lines |
| `ProfileTabHandler.php` | Member profile tab registration and rendering | ~400 lines |
| `GroupTabHandler.php` | Group tab registration and rendering | ~400 lines |
| `ActivityFormHandler.php` | Activity form hooks and AJAX | ~300 lines |

Hook names and filter signatures must remain identical to avoid breaking third-party code.

**What actually shipped** (names differ from the plan above): `includes/Integrations/BuddyPress/` now holds `BuddyPressManager`, `ActivitySyncIntegration`, `ActivityContentIntegration`, `ActivityFormIntegration`, `ActivityMediaLinkage`, `ActivityPrivacyFilter`, `ProfileTabIntegration`, `GroupTabIntegration`, `NotificationIntegration`, plus the shared `BaseBPTabIntegration` and `MediaDisplayHelper`.

**Definition of Done**
Each class is under 500 lines; all existing hooks fire with identical signatures; no behavior regression; original file removed.

**Why this matters**
A 2,811-line integration file cannot be reviewed, tested, or safely modified - every touch to it risks breaking unrelated BP behavior.

---

### 1.3 Split SettingsPage.php - ✅ DONE

**Current state**
`includes/Admin/SettingsPage.php` - 2,401 lines. Five tabs are rendered inline with direct `echo` and `sprintf` calls mixed throughout business logic. Registration, validation, and output are interleaved.

**Target state**
Three PHP classes and five template files:

- `includes/Admin/SettingsManager.php` - registration and sanitization only
- `includes/Admin/SettingsRenderer.php` - shared rendering helpers (field rows, section headers)
- `templates/admin/settings-general.php`
- `templates/admin/settings-display.php`
- `templates/admin/settings-permissions.php`
- `templates/admin/settings-ai.php`
- `templates/admin/settings-webhooks.php`

**What actually shipped** (names differ from the plan above): `includes/Admin/Settings/` now holds `SettingsPage`, `SettingsRegistrar`, `FieldRenderer`, `PermissionsManager` and `Sanitizers`, plus `AiSettingsRegistrar`. There is no `SettingsManager` or `SettingsRenderer`. `SettingsRegistrar` remains the one OPEN debt row in `CLAUDE.md`.

**Definition of Done**
No HTML in the registrar; all output routed through templates; rendering helpers are reused by all templates.

**Why this matters**
Inline HTML in logic classes makes settings untestable and prevents the design system from being applied consistently.

---

## Priority 2 - Pro Stabilization (PLANNED - none of these have shipped)

### 2.1 Split MigrationPage.php (Pro) - PARTLY OVERTAKEN BY EVENTS

**Current state (as written; stale)**
`includes/Admin/MigrationPage.php` (Pro) was 1,776 lines with detection logic, batch processing logic, and HTML in one class. It is now well under the 500-line limit, and the batch/detect logic moved into `includes/Integrations/AbstractBatchImporter.php` and the per-platform importers (see 2.2) rather than into the `MigrationDetector` / `MigrationBatcher` classes proposed below. Those two class names were never created - do not go looking for them.

**Target state**
- `includes/Admin/MigrationDetector.php` - source detection, source counting, compatibility checks
- `includes/Admin/MigrationBatcher.php` - batch state machine, progress tracking, error recovery
- `templates/admin/migration.php` - all HTML output

**Definition of Done**
Each class under 500 lines; `MigrationDetector` and `MigrationBatcher` are independently instantiable without a page context; template is the only place HTML is echoed.

**Why this matters**
Detection and batching need to be callable from WP-CLI without triggering admin page rendering - splitting is a prerequisite for that feature.

---

### 2.2 Extract AbstractBatchImporter (Pro) - ✅ DONE

**Current state**
Three CLI importers each copy-pasted the same batch loop, flag parsing, progress bar, state persistence, and duplicate detection logic.

**Shipped**
- `includes/Integrations/AbstractBatchImporter.php` - abstract base with the shared batch machinery
- `includes/Integrations/RtMedia/Importer.php` (`wp mvs import-rtmedia`)
- `includes/Integrations/MediaPress/Importer.php` (`wp mvs import-mediapress`)
- `includes/Integrations/BuddyBoss/Importer.php` (`wp mvs import-buddyboss`)
- `includes/CLI/ImportThumbnailTrait.php` remains shared across all three subclasses

All three `extends AbstractBatchImporter`. Note the final location differs from the `includes/CLI/Import*.php` layout proposed at planning time.

**Definition of Done**
No batch loop logic duplicated across subclasses; each subclass under 300 lines; existing CLI command signatures unchanged; all three importers pass existing integration tests.

**Why this matters**
Duplicate batch logic has diverged across the three importers - bugs fixed in one are not fixed in the others.

---

### 2.3 Split MessagingService (Both Plugins) - PLANNED

**Current state (as written; stale)**
`MessagingService.php` (Free) was 1,606 lines mixing access control, rate limiting, thread CRUD, and message delivery, and it has grown since. It now lives at `includes/Messaging/MessagingService.php`, not `includes/Services/`. Pro has no parallel messaging service - the engine is Free-only. Check the line count before quoting one.

**Target state**
- `includes/Messaging/MessagingPrivacy.php` - access control checks and rate limiting only
- `includes/Messaging/MessagingService.php` - thread and message CRUD, stripped of all gate-keeping logic

`MessagingPrivacy` is called by `MessagingService` before any mutation. Both are registered as services in the container.

**Definition of Done**
Each class under 500 lines; all existing public method signatures on `MessagingService` preserved; access control logic not duplicated between Free and Pro variants.

**Why this matters**
Rate limiting and privacy logic buried inside a CRUD service cannot be independently audited or overridden by Pro.

---

## Priority 3 - Quality Infrastructure (PLANNED unless noted)

### 3.1 Add Interfaces for the Free/Pro Boundary - PLANNED (partially superseded)

> Since this was written, `MediaRepositoryInterface`, `TemplateHelpersInterface`,
> `StorageDriverInterface` and `AIProviderInterface` all ship, and Pro's
> `bin/coding-rules-check.sh` Rule 3 mechanically blocks direct imports of Free
> concrete classes. The `includes/Contracts/` directory below was never created -
> interfaces live beside the classes they describe.

**Current state (as written; stale)**
Pro imported Free classes directly, via a `MediaMeta` class referenced in 12+ Pro files. That class no longer exists in either plugin, and Pro no longer imports Free concrete classes.

**Target state**
`includes/Contracts/` directory with interface files:

- `MediaMetaInterface.php` - all public methods that Pro depends on
- `StorageDriverInterface.php` - already exists; verify it is complete
- `AIProviderInterface.php` - already exists; verify it is complete

Free classes implement their interfaces. Pro uses interface type hints only - no direct imports of Free concrete classes.

**Definition of Done**
Zero direct imports of Free concrete classes in Pro; all replaced with interface type hints or `Plugin::free_service()` lookups. This is now enforced by Pro's `bin/coding-rules-check.sh` Rule 3.

**Why this matters**
Direct class coupling between Free and Pro means a Free refactor can silently break Pro at runtime with no static analysis warning.

---

### 3.2 Increase Test Coverage - PLANNED (Free)

**Current state (as written; stale)**
~10% coverage, 11 test files under `tests/unit/`. The file count has grown a long way past that since - count it with `ls tests/unit/*.php | wc -l` rather than trusting a number here - but coverage has never been measured in CI, so the 50% threshold below is still not enforced.

**Target state**
50%+ line coverage measured in CI on every push.

Priority test suites to add:

| Suite | Key scenarios |
|---|---|
| `BuddyPressIntegrationTest` | Activity sync, notification dispatch, tab rendering |
| `MessagingServiceTest` | Thread creation, rate limiting, access denial |
| `SettingsManagerTest` | Registration, sanitization, defaults |
| `StorageDriverTest` | Local, S3, external URL drivers |
| `AIProviderTest` | Moderation pass/fail/skip paths |

**Definition of Done**
CI reports coverage; threshold is 50%; all new classes added in P1 and P2 have test coverage at the time they are merged.

**Why this matters**
At 10% coverage, structural refactoring cannot be validated - regressions are only caught in production.

---

### 3.3 Admin Page Template Extraction - PLANNED

**Current state**
Admin pages across both plugins render HTML directly via `echo` and `sprintf` inside PHP controller classes. This pattern is inconsistent, untestable, and prevents global layout changes from being applied in one place.

**Target state**
All admin HTML lives in `templates/admin/` files. PHP classes call a shared `render_template( string $template, array $data )` helper and pass data as a scoped array. No `echo` of HTML tags in controller classes.

Applies to all admin pages: migration, stats, moderation, settings, quota, and any page added in future.

**Definition of Done**
Zero `echo '<div` or `printf( '<` patterns in `includes/Admin/*.php`; all admin output routed through `templates/admin/`; template helper is shared between Free and Pro.

**Why this matters**
Inline HTML in controllers blocks design system adoption and makes admin pages impossible to snapshot test.

---

### 3.4 Extract Plugin.php Feature Bootstrappers (Pro) - PLANNED

**Current state**
Pro's `Plugin.php` has an `init()` method of ~822 lines. Route registration, admin page registration, cron scheduling, and feature flag checks for every Pro feature are all inlined in this one method.

**Target state**
`includes/Features/` directory. Each Pro feature becomes a self-contained bootstrapper:

- `QuotaFeature.php` - quota routes, admin quota page, quota cron
- `GamificationFeature.php` - gamification hooks, manifest registration
- `DocumentsFeature.php` - document routes, drives admin page
- Additional feature classes as Pro features are added

(The original draft listed a `VideoFeature.php` for "video processing routes". There is no video-processing feature: the FFmpeg transcoding path was removed in 2.4.0 and Coding Rule 21 bans exec-family calls outright. WPMediaVerse stores and embeds video; it does not process it.)

Each feature class implements a `register(): void` method. `Plugin::init()` iterates an array of feature class names and calls `register()` on each.

**Definition of Done**
`Plugin.php::init()` under 300 lines; each feature class under 200 lines; each feature is independently instantiable and independently testable; no behavior regression.

**Why this matters**
An 822-line init method makes it impossible to enable or disable Pro features conditionally, test them in isolation, or understand what a feature actually touches.

---

## Sequencing Notes

Work the items in priority order. P1 items share a dependency: once `MediaRepository` (1.1) exists, the `BuddyPressIntegration` split (1.2) and the `SettingsPage` split (1.3) become easier because they can delegate media queries to the repository rather than embedding them.

P2 and P3 items are largely independent of each other and can be parallelized across contributors. None of them are scheduled against a version - pick one up when the code it touches is already being opened, and check `CLAUDE.md`'s Known Debt table first, since it is the current-state view and this file is not.

# WPMediaVerse Refactoring Roadmap

This is the prioritized structural refactoring backlog for the WPMediaVerse free and pro plugins. These are architectural changes — not style fixes — that reduce coupling, improve testability, and make the codebase maintainable as the feature set grows.

P1 items must be completed before v1.2.0 ships. The API surface is still small; every week that passes makes these extractions harder.

## Status

P1 items are **all done** in 1.1.x. P2 + P3 items are scheduled inside the per-plugin `docs/superpowers/plans/2026-04-28-1.2.0-milestone.md` plans (Free + Pro) — that single plan file is the working source of truth for 1.2.0 task tracking. Per-item status is recorded inline below.

---

## Priority 1 — Foundation (Complete Before v1.2.0)

### 1.1 Extract MediaRepository — ✅ DONE

**Current state**
~842 `$wpdb` calls are scattered across 30+ files. There is no single place that owns media data access; SQL is duplicated, inconsistently escaped, and impossible to mock in tests.

**Target state**
`includes/Repository/MediaRepository.php` — a single class that owns every media query.

Required public methods:
- `get_by_id( int $id ): ?array`
- `get_by_slug( string $slug ): ?array`
- `get_published_count( array $args = [] ): int`
- `get_moderation_counts(): array`
- `get_by_author( int $user_id, array $args = [] ): array`

All existing callers across free and pro must be updated to use the repository. No raw `$wpdb` calls for media data outside this class.

**Definition of Done**
All media queries route through `MediaRepository`; all call sites updated; existing behavior unchanged; class is injectable (no static methods).

**Why this matters**
Untestable data access spread across 30+ files is the single biggest barrier to meaningful test coverage.

---

### 1.2 Split BuddyPressIntegration.php — ✅ DONE

**Current state**
`includes/Integrations/BuddyPressIntegration.php` — 2,811 lines handling activity sync, notifications, profile tabs, group tabs, and form handling in one class. Six concerns, one file.

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

**Definition of Done**
Each class is under 500 lines; all existing hooks fire with identical signatures; no behavior regression; original file removed.

**Why this matters**
A 2,811-line integration file cannot be reviewed, tested, or safely modified — every touch to it risks breaking unrelated BP behavior.

---

### 1.3 Split SettingsPage.php — ✅ DONE

**Current state**
`includes/Admin/SettingsPage.php` — 2,401 lines. Five tabs are rendered inline with direct `echo` and `sprintf` calls mixed throughout business logic. Registration, validation, and output are interleaved.

**Target state**
Three PHP classes and five template files:

- `includes/Admin/SettingsManager.php` — registration and sanitization only
- `includes/Admin/SettingsRenderer.php` — shared rendering helpers (field rows, section headers)
- `templates/admin/settings-general.php`
- `templates/admin/settings-display.php`
- `templates/admin/settings-permissions.php`
- `templates/admin/settings-ai.php`
- `templates/admin/settings-webhooks.php`

**Definition of Done**
No HTML in `SettingsManager`; all output routed through templates; rendering helpers in `SettingsRenderer` are reused by all five templates; pattern matches `AdminController` structure where it exists.

**Why this matters**
Inline HTML in logic classes makes settings untestable and prevents the design system from being applied consistently.

---

## Priority 2 — Pro Stabilization

### 2.1 Split MigrationPage.php (Pro) — 1.2.0 Phase 5

**Current state**
`includes/Admin/MigrationPage.php` (Pro) — 1,776 lines. Detection logic, batch processing logic, and HTML are all in one class. Impossible to run detection without triggering rendering.

**Target state**
- `includes/Admin/MigrationDetector.php` — source detection, source counting, compatibility checks
- `includes/Admin/MigrationBatcher.php` — batch state machine, progress tracking, error recovery
- `templates/admin/migration.php` — all HTML output

**Definition of Done**
Each class under 500 lines; `MigrationDetector` and `MigrationBatcher` are independently instantiable without a page context; template is the only place HTML is echoed.

**Why this matters**
Detection and batching need to be callable from WP-CLI without triggering admin page rendering — splitting is a prerequisite for that feature.

---

### 2.2 Extract AbstractBatchImporter (Pro) — 1.2.0 Phase 5

**Current state**
Three CLI importers — `ImportRtMedia`, `ImportMediaPress`, `ImportBuddyBoss` — each copy-paste the same batch loop, flag parsing, progress bar, state persistence, and duplicate detection logic. Changes to shared behavior require touching all three files.

**Target state**
- `includes/CLI/AbstractBatchImporter.php` — base class with all shared logic
  - Handles: flag parsing, batch loop, progress bar, state persistence, duplicate detection
  - Declares abstract methods: `get_source_count()`, `fetch_batch( int $offset, int $limit )`, `import_item( array $row )`
- `includes/CLI/ImportRtMedia.php` — extends base, implements platform-specific logic only
- `includes/CLI/ImportMediaPress.php` — same
- `includes/CLI/ImportBuddyBoss.php` — same
- `ImportThumbnailTrait` remains shared across all three subclasses

**Definition of Done**
No batch loop logic duplicated across subclasses; each subclass under 300 lines; existing CLI command signatures unchanged; all three importers pass existing integration tests.

**Why this matters**
Duplicate batch logic has diverged across the three importers — bugs fixed in one are not fixed in the others.

---

### 2.3 Split MessagingService (Both Plugins) — 1.2.0 Phase 5

**Current state**
`includes/Services/MessagingService.php` (Free) — 1,606 lines mixing access control, rate limiting, thread CRUD, and message delivery. Pro has a parallel file of similar size.

**Target state**
- `includes/Services/MessagingPrivacy.php` — access control checks and rate limiting only
- `includes/Services/MessagingService.php` — thread and message CRUD, stripped of all gate-keeping logic

`MessagingPrivacy` is called by `MessagingService` before any mutation. Both are registered as services in the container.

**Definition of Done**
Each class under 500 lines; all existing public method signatures on `MessagingService` preserved; access control logic not duplicated between Free and Pro variants.

**Why this matters**
Rate limiting and privacy logic buried inside a CRUD service cannot be independently audited or overridden by Pro.

---

## Priority 3 — Quality Infrastructure

### 3.1 Add Interfaces for the Free/Pro Boundary — 1.2.0 Phase 1

**Current state**
Pro imports Free classes directly. `MediaMeta` is used in 12+ Pro files via direct class reference. If Free refactors `MediaMeta`, Pro breaks. No contracts exist to stabilize this boundary.

**Target state**
`includes/Contracts/` directory with interface files:

- `MediaMetaInterface.php` — all public methods that Pro depends on
- `StorageDriverInterface.php` — already exists; verify it is complete
- `AIProviderInterface.php` — already exists; verify it is complete

Free classes implement their interfaces. Pro uses interface type hints only — no direct imports of Free concrete classes.

**Definition of Done**
Zero `use WPMediaVerse\Includes\MediaMeta;` statements in Pro files; all replaced with interface type hints; Free classes declare `implements MediaMetaInterface`.

**Why this matters**
Direct class coupling between Free and Pro means a Free refactor can silently break Pro at runtime with no static analysis warning.

---

### 3.2 Increase Test Coverage — 1.2.0 Phase 4 (Free)

**Current state**
~10% coverage. 11 test files exist under `tests/unit/`. Core services — BP integration, messaging, settings, storage, AI moderation — have no unit tests.

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
At 10% coverage, structural refactoring cannot be validated — regressions are only caught in production.

---

### 3.3 Admin Page Template Extraction — deferred to 1.3

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

### 3.4 Extract Plugin.php Feature Bootstrappers (Pro) — deferred to 1.3

**Current state**
Pro's `Plugin.php` has an `init()` method of ~822 lines. Route registration, admin page registration, cron scheduling, and feature flag checks for every Pro feature are all inlined in this one method.

**Target state**
`includes/Features/` directory. Each Pro feature becomes a self-contained bootstrapper:

- `QuotaFeature.php` — quota routes, admin quota page, quota cron
- `VideoFeature.php` — video processing routes, video admin page
- `GamificationFeature.php` — gamification hooks, manifest registration
- Additional feature classes as Pro features are added

Each feature class implements a `register(): void` method. `Plugin::init()` iterates an array of feature class names and calls `register()` on each.

**Definition of Done**
`Plugin.php::init()` under 300 lines; each feature class under 200 lines; each feature is independently instantiable and independently testable; no behavior regression.

**Why this matters**
An 822-line init method makes it impossible to enable or disable Pro features conditionally, test them in isolation, or understand what a feature actually touches.

---

## Sequencing Notes

Work the items in priority order. P1 items share a dependency: once `MediaRepository` (1.1) exists, the `BuddyPressIntegration` split (1.2) and the `SettingsPage` split (1.3) become easier because they can delegate media queries to the repository rather than embedding them.

P2 and P3 items are largely independent of each other and can be parallelized across contributors once P1 is complete.

Do not ship v1.2.0 with P1 items incomplete. The larger the installed base, the more careful any future structural change to `BuddyPressIntegration` or `SettingsPage` must be.

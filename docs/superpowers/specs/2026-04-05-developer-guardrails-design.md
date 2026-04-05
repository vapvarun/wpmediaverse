# WPMediaVerse Developer Guardrails & Documentation Suite

**Date:** 2026-04-05
**Status:** Design Approved
**Scope:** Both `wpmediaverse` (Free) and `wpmediaverse-pro` (Pro)

---

## Context

WPMediaVerse is a public-repo WordPress plugin ecosystem (Free + Pro) aiming for WooCommerce/WordPress-level code quality. Currently:

- **Zero developer docs** — no CLAUDE.md, ARCHITECTURE.md, CONTRIBUTING.md
- **13 god classes** (6 in Free, 7 in Pro) exceeding 500 lines
- **842 scattered `$wpdb` calls** with no repository layer
- **~10% test coverage** — major modules untested
- **No coding standards doc** — patterns are implicit, inconsistent
- **No Free/Pro boundary contract** — Pro imports Free classes directly

The codebase has strong fundamentals (PSR-4, service container, security, logging) but lacks the documentation and guardrails to keep quality high as the team grows.

**Goal:** Create a complete documentation suite so any developer or AI can:
1. Understand the architecture without reading every file
2. Follow consistent patterns for any task
3. Know what to refactor and in what order
4. Never repeat the mistakes that created the current debt

---

## Documents to Create

### Free Plugin (`wpmediaverse/`)

| File | Purpose | Audience |
|------|---------|----------|
| `CLAUDE.md` | AI-first rules, module map, boundaries, quick reference | AI + devs |
| `docs/ARCHITECTURE.md` | Database schema, service container, hooks, REST API, lifecycle | Devs + AI |
| `docs/CODING_STANDARDS.md` | Hard rules with limits and rationale | All contributors |
| `docs/CONTRIBUTING.md` | Step-by-step guides for common tasks | New developers |
| `docs/REFACTORING_ROADMAP.md` | Prioritized cleanup list with scope estimates | Tech leads |
| `docs/EXTENSION_GUIDE.md` | How to create drivers, providers, importers | Plugin extenders |
| `docs/SECURITY_CHECKLIST.md` | Security review checklist for every PR | All contributors |
| `docs/GIT_WORKFLOW.md` | Commit conventions, branch naming, PR process | All contributors |

### Pro Plugin (`wpmediaverse-pro/`)

| File | Purpose | Audience |
|------|---------|----------|
| `CLAUDE.md` | Pro-specific rules, extension pattern, feature toggles | AI + devs |
| `docs/ARCHITECTURE.md` | Pro features, competition system, video pipeline | Devs + AI |

Pro docs reference Free docs — no duplication.

---

## Document Content Specifications

### 1. CLAUDE.md (Free Plugin)

```
# WPMediaVerse

## Quick Facts
- Version: 1.1.0
- PHP: 8.1+ | WP: 6.4+
- Namespace: WPMediaVerse\
- Autoloading: PSR-4 via Composer
- Architecture: Service container + WordPress hooks
- Text domain: wpmediaverse
- Custom tables: 14 (prefixed mvs_)
- REST endpoints: 20 (namespace mvs/v1)

## Module Map
Core/          → Bootstrap, ServiceContainer, Migrator, Loader, TemplateLoader, TemplateHelpers
Admin/         → SettingsPage, OverviewPage, ModerationQueue, StatsPage, LogViewerPage, MediaListPage, SetupWizard, CollectionMetaBox
REST/          → 20 controllers: Media, Album, Collection, Bulk, Reaction, Comment, Favorite, Stats, Tag, Moderation, Access, SignedUrl, Follow, Notification, User, Report, Activity, Profile, Messaging, Conversation
Services/      → UploadService, StorageService, PrivacyService, AlbumService, CollectionService, StoryService, AIService, ModerationService, CacheService, StatsService, AccessRulesService, SignedUrlService, WatermarkService, MediaMeta, ProfileService, LoggerService
Social/        → ReactionService, CommentService, FavoriteService, FollowService, NotificationService, ReportService, ActivityService, MentionService, ShareService
Integrations/  → BuddyPressIntegration, WebhookService
PostTypes/     → Album (mvs_album), Collection (mvs_collection)
Taxonomies/    → MediaTag (mvs_media_tag), MediaCategory (mvs_media_category)
Blocks/        → BlockRegistrar (Gutenberg blocks)
Shortcodes/    → Shortcodes
CLI/           → Commands (WP-CLI)
Messaging/     → MessagingService, MessagingController, ConversationController
Capabilities/  → MediaCapabilities (custom caps: manage_mvs_media, manage_mvs_access, manage_mvs_settings, moderate_mvs_media)

## Service Container Keys
(See docs/ARCHITECTURE.md for full registry)

## Database Tables
(See docs/ARCHITECTURE.md for schema)

## Coding Rules
1. Max file size: 500 lines — split before adding code to oversized files
2. Max method size: 50 lines
3. Database queries: use $wpdb->prepare() always, plan migration to repository pattern
4. Admin HTML: template files in templates/admin/, never inline echo
5. Hook names: mvs_ prefix, snake_case (e.g., mvs_media_uploaded)
6. REST endpoints: extend WP_REST_Controller with schema + permission callback
7. Security: nonce + capability check on every write operation
8. Error handling: use WP_Error or LoggerService, never silent failures
9. i18n: all user-facing strings wrapped in __() / esc_html__()
10. Pro boundary: Pro must never import Free classes directly — use service container or hooks

## Known Debt (Do Not Worsen)
| File | Lines | Status |
|------|-------|--------|
| BuddyPressIntegration.php | 2,811 | Needs split into 4-5 classes |
| SettingsPage.php | 2,401 | Needs split into 3 classes + templates |
| MessagingService.php | 1,606 | Needs split into 2 classes |
| Plugin.php | 1,208 | Acceptable for bootstrap, do not grow |
| MediaController.php | 1,105 | Needs query extraction to repository |
| MessagingController.php | 803 | Needs query extraction |

See docs/REFACTORING_ROADMAP.md for priorities.

## Testing
- Framework: PHPUnit 9.6 with yoast/phpunit-polyfills
- Run: composer test or ./vendor/bin/phpunit
- 11 test files in tests/unit/
- Coverage: ~10% (target: 50%+)

## Build & Release
- Build: npx grunt dist (minifies CSS/JS, creates dist ZIP)
- WPCS: composer run phpcs
- PHPStan: composer run phpstan
- QA: Use WP Plugin QA MCP tools (wppqa_audit_plugin, wppqa_run_code_checks)

## Recent Changes
(Updated after each significant commit)
```

### 2. CLAUDE.md (Pro Plugin)

```
# WPMediaVerse Pro

## Quick Facts
- Version: 1.1.0
- Extends: wpmediaverse (Free) via mvs_loaded hook
- Namespace: WPMediaVersePro\
- Text domain: wpmediaverse-pro
- License: EDD Software Licensing

## How Pro Extends Free
1. Pro hooks into do_action('mvs_loaded') in wpmediaverse-pro.php
2. Pro accesses Free services via Plugin::free_service('key')
3. Pro adds admin tabs via filters (mvs_moderation_tabs, mvs_stats_tabs)
4. Pro registers storage drivers via mvs_storage_driver filter
5. Pro registers AI providers via mvs_ai_providers action

## IMPORTANT: Never import Free plugin classes directly
Use Plugin::free_service() or hooks. Direct imports create tight coupling.
Exception: WPMediaVerse\Services\MediaMeta (used in 12+ files — earmarked for interface extraction)

## Module Map
Admin/         → ProSettings, MigrationPage, QuotaPage, AnalyticsDashboard, ReportManager, ChallengeManager, TournamentManager, BattleMonitor, CompetitionsDashboard, ThemeLibrary, GamificationSettings
Battles/       → BattleService + BattleController (toggle: mvs_battles_enabled)
Challenges/    → ChallengeService + ChallengeController + AutopilotService (toggle: mvs_challenges_enabled)
Tournaments/   → TournamentService + TournamentController (toggle: mvs_tournaments_enabled)
Boosts/        → BoostService + BoostController
Streaks/       → StreakService
Video/         → TranscodeService, ChapterService, ResumeService, VideoController, TranscodeController
Captions/      → TranscriptionService, CaptionController
Analytics/     → AnalyticsService, AnalyticsController
Quota/         → QuotaService, QuotaController + Adapters (MemberPress, PaidMemberships, WooCommerce)
Storage/       → S3Driver, BunnyCDNDriver
AI/            → GoogleVisionProvider, RekognitionProvider
CLI/           → ImportRtMedia, ImportMediaPress, ImportBuddyBoss + ImportThumbnailTrait
Frontend/      → LayoutManager, UsageWidget, GamificationTemplateLoader
Privacy/       → PrivacyUIService, PrivacyController
Messaging/     → NotificationListener
Core/          → Plugin, Migrator, LicenseManager, ConnectionTester

## Feature Toggles
Every competition feature has a settings toggle:
- mvs_battles_enabled (default: 0)
- mvs_challenges_enabled (default: 0)
- mvs_tournaments_enabled (default: 0)

## Coding Rules
Same as Free plugin (see wpmediaverse/docs/CODING_STANDARDS.md) plus:
1. Every new feature needs a settings toggle
2. CLI importers must extend base importer (to be created)
3. Admin pages follow same template pattern as Free

## Known Debt
| File | Lines | Status |
|------|-------|--------|
| MigrationPage.php | 1,776 | Needs split: Detector + Batcher + Template |
| MessagingService.php | 1,596 | Needs split: Privacy + CRUD |
| ProSettings.php | 1,055 | Needs split: Manager + Renderer |
| TranscodeService.php | 1,000 | Needs split: Logic + Infrastructure |
| TournamentService.php | 899 | Complex but acceptable for now |
| ChallengeService.php | 748 | Complex but acceptable for now |
| AnalyticsService.php | 740 | Complex but acceptable for now |
| Plugin.php | 822 | Extract feature bootstrappers |

## References
- Architecture: ../wpmediaverse/docs/ARCHITECTURE.md
- Coding Standards: ../wpmediaverse/docs/CODING_STANDARDS.md
- Contributing: ../wpmediaverse/docs/CONTRIBUTING.md
- Security: ../wpmediaverse/docs/SECURITY_CHECKLIST.md
- Git Workflow: ../wpmediaverse/docs/GIT_WORKFLOW.md
```

### 3. docs/ARCHITECTURE.md

Sections:
1. **Plugin Lifecycle** — Init order diagram (wpmediaverse.php → Plugin::init() → services → hooks → mvs_loaded → Pro)
2. **Service Container** — Full registry of all service keys with their classes
3. **Database Schema** — All 14 tables with columns, types, indexes, relationships
4. **REST API Map** — All 20 endpoints grouped by resource (media, albums, social, moderation)
5. **Hook Reference** — All mvs_* hooks grouped by lifecycle (upload, moderation, social, admin, settings)
6. **Template System** — TemplateLoader, TemplateHelpers, frontend templates, admin templates
7. **Free → Pro Boundary** — How Pro registers, what it accesses, version compatibility

### 4. docs/CODING_STANDARDS.md

Hard rules with enforcement:

| Category | Rule | Limit | Enforcement |
|----------|------|-------|-------------|
| File size | Max lines per file | 500 | Code review + PHPStan (future) |
| Method size | Max lines per method | 50 | Code review |
| Database | Always use $wpdb->prepare() | No raw queries | WPCS phpcs |
| Admin HTML | Use template files | No inline echo in methods | Code review |
| Hooks | mvs_ prefix, snake_case | Consistent naming | Code review |
| REST | Extend WP_REST_Controller | Schema + permissions required | Code review |
| Security | Nonce + caps on writes | No exceptions | WP Plugin QA MCP |
| Escaping | esc_html/esc_attr/esc_url on output | Every variable | WPCS phpcs |
| Sanitization | sanitize_text_field etc on input | Every $_GET/$_POST | WPCS phpcs |
| i18n | Text domain wpmediaverse | All strings | WPCS phpcs |
| Error handling | WP_Error or LoggerService | No silent failures | Code review |
| Dependencies | Use service container | No direct instantiation in controllers | Code review |

Anti-patterns with examples:
- God class: file over 500 lines → split by responsibility
- Inline SQL: raw $wpdb without prepare → use prepare() or repository
- Copy-paste: same logic in 2+ places → extract to shared service
- Tight coupling: Pro importing Free class → use service container key
- Silent failure: catch with no log/error → add LoggerService call

### 5. docs/CONTRIBUTING.md

Step-by-step task guides:

**How to add a new feature (Free)**
1. Create service class in `includes/Services/` (<500 lines)
2. Register in service container (`Plugin::register_services()`)
3. Create REST controller in `includes/REST/Controller/` extending `WP_REST_Controller`
4. Register routes via `rest_api_init`
5. Add admin UI in `includes/Admin/` with template in `templates/admin/`
6. Add hooks with `mvs_` prefix for extensibility
7. Write unit test in `tests/unit/`
8. Update CLAUDE.md module map

**How to add a REST endpoint**
1. Create controller extending `WP_REST_Controller`
2. Define `register_routes()` with methods, callbacks, permission callbacks
3. Add schema via `get_item_schema()`
4. Sanitize all args via `sanitize_callback`
5. Return `WP_Error` for failures, `WP_REST_Response` for success
6. Register in Plugin.php via `rest_api_init`

**How to fix a bug**
1. Reproduce and identify the module (use Module Map in CLAUDE.md)
2. Check existing tests for the area
3. Write a failing test first (if testable)
4. Fix the issue
5. Run phpcs + phpstan + tests
6. Run WP Plugin QA MCP check

**How to add a competition type (Pro)**
1. Create `includes/YourCompetition/YourService.php` — business logic
2. Create `includes/YourCompetition/YourController.php` — REST endpoints
3. Create `includes/Admin/YourManager.php` — admin UI
4. Add settings toggle in `ProSettings.php`
5. Register in `Plugin.php::init()` with feature toggle check
6. Add hooks for gamification integration
7. Add to CompetitionsDashboard stat cards

**How to add a storage driver (Pro)**
1. Create class in `includes/Storage/` implementing `StorageDriverInterface`
2. Implement: `upload()`, `delete()`, `get_url()`, `exists()`
3. Register via `mvs_storage_driver` filter
4. Add settings section in `ProSettings.php`
5. Test with WP-CLI: `wp mvs test-connection`

**How to add an AI provider (Pro)**
1. Create class in `includes/AI/` implementing `AIProviderInterface`
2. Implement: `analyze()`, `moderate()`, `tag()`
3. Register via `mvs_ai_providers` action
4. Add API key settings in `ProSettings.php`

**How to add a migration importer (Pro)**
1. Create CLI command in `includes/CLI/` extending future `AbstractBatchImporter`
2. Implement: `get_source_count()`, `fetch_batch()`, `import_item()`
3. Register WP-CLI command in `wpmediaverse-pro.php`
4. Add platform card in `MigrationPage.php` (will be template-based after refactor)
5. Add AJAX detection handler

### 6. docs/REFACTORING_ROADMAP.md

Prioritized by impact, with scope and definition of done:

**Priority 1 — Foundation (blocks future debt)**

| # | Item | Current State | Target State | Files | DoD |
|---|------|--------------|-------------|-------|-----|
| 1.1 | Extract MediaRepository | 842 scattered $wpdb calls | Central data access class | New: includes/Repository/MediaRepository.php | All media queries go through repository; existing callers updated |
| 1.2 | Split BuddyPressIntegration | 2,811 lines, 1 god class | 5 focused classes | Split into: ActivitySync, NotificationBridge, ProfileTab, GroupTab, ActivityForm | Each class <500 lines, existing behavior preserved |
| 1.3 | Split SettingsPage | 2,401 lines, rendering + logic | 3 classes + templates | SettingsManager, SettingsRenderer, templates/admin/settings-*.php | HTML in templates, logic in manager |

**Priority 2 — Pro stabilization**

| # | Item | Current State | Target State | Files | DoD |
|---|------|--------------|-------------|-------|-----|
| 2.1 | Split MigrationPage | 1,776 lines, monolithic | 3 classes + template | MigrationDetector, MigrationBatcher, templates/admin/migration.php | Each <500 lines |
| 2.2 | Extract AbstractBatchImporter | 3 copy-pasted CLI importers | Base class + 3 subclasses | New: includes/CLI/AbstractBatchImporter.php | Common batch logic in base, platform logic in subclasses |
| 2.3 | Split MessagingService (both) | 1,606 lines each | 2 classes each | MessagingPrivacy + MessagingService (CRUD only) | Each <500 lines |

**Priority 3 — Quality infrastructure**

| # | Item | Current State | Target State | Files | DoD |
|---|------|--------------|-------------|-------|-----|
| 3.1 | Add interfaces for Free/Pro boundary | Direct class imports | Interface contracts | New: includes/Contracts/ directory | Pro uses interfaces, Free implements them |
| 3.2 | Increase test coverage | ~10% (11 tests) | 50%+ | New tests for untested modules | BP integration, messaging, settings, storage tested |
| 3.3 | Admin page template extraction | Inline HTML in PHP | templates/admin/ files | Move all admin page HTML to templates | Consistent rendering pattern |
| 3.4 | Extract Plugin.php bootstrappers (Pro) | 822-line init() | Feature bootstrapper classes | New: includes/Features/ directory | Each feature self-contained |

### 7. docs/SECURITY_CHECKLIST.md

Every PR touching user-facing code must verify:

**Input Layer**
- [ ] All `$_GET`, `$_POST`, `$_REQUEST` sanitized (`sanitize_text_field`, `absint`, etc.)
- [ ] File uploads validated (`wp_check_filetype_and_ext`, size limits)
- [ ] JSON request bodies decoded with `json_decode` + validated

**Authentication & Authorization**
- [ ] Nonce verified on all form submissions and AJAX handlers
- [ ] `current_user_can()` checked before any write operation
- [ ] REST endpoints have `permission_callback` (never `__return_true` on write endpoints)
- [ ] Custom capabilities used where appropriate (`manage_mvs_media`, `moderate_mvs_media`)

**Database**
- [ ] All queries use `$wpdb->prepare()` with typed placeholders
- [ ] No string interpolation in SQL (even for table names — use `$wpdb->prefix`)
- [ ] LIKE queries use `$wpdb->esc_like()` before `$wpdb->prepare()`

**Output**
- [ ] All output escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- [ ] `wp_kses_post()` for rich content (never `wp_kses_allowed_html` bypass)
- [ ] No raw `echo $variable` — always escape

**AJAX/REST**
- [ ] AJAX handlers check `check_ajax_referer()` or `wp_verify_nonce()`
- [ ] REST responses don't leak sensitive data (user emails, IPs, internal paths)
- [ ] Rate limiting on expensive operations (uploads, AI calls, messaging)

**File System**
- [ ] No user-controlled paths in `file_get_contents` / `include` / `require`
- [ ] Upload directories have `.htaccess` denying PHP execution
- [ ] Temporary files cleaned up after processing

**Automated Checks**
- Run `wppqa_run_code_checks` via WP Plugin QA MCP before every PR
- Run `wpcs_check_file` on changed files via WPCS MCP
- Run `wpcs_phpstan_check` for static analysis

### 8. docs/GIT_WORKFLOW.md

**Branch Strategy**
- `main` — stable release branch, locked from direct push
- `develop` — integration branch for next release
- `feature/<name>` — new features (branch from develop)
- `fix/<name>` — bug fixes (branch from develop)
- `release/<version>` — release preparation (branch from develop)

**Commit Message Convention**
```
<type>(<scope>): <subject>

<body — optional, explain WHY not WHAT>
```

Types: `feat`, `fix`, `refactor`, `docs`, `test`, `build`, `chore`
Scopes: `admin`, `rest`, `social`, `bp`, `messaging`, `cli`, `migration`, `video`, `analytics`, `quota`, `security`

Examples:
```
feat(rest): add batch delete endpoint for media
fix(bp): prevent duplicate activity entries on album reorder
refactor(admin): extract SettingsRenderer from SettingsPage
docs: add ARCHITECTURE.md with full database schema
test(messaging): add integration tests for rate limiting
```

**PR Process**
1. Branch from `develop`
2. Write code following CODING_STANDARDS.md
3. Run SECURITY_CHECKLIST.md on your changes
4. Run tests: `composer test`
5. Run WPCS: `composer run phpcs`
6. Run PHPStan: `composer run phpstan`
7. Run WP Plugin QA MCP: `wppqa_audit_plugin`
8. Create PR with description template:
   - Summary (what + why)
   - Test plan (how to verify)
   - Checklist (security, tests, docs updated)
9. Merge to develop after review
10. Tag release from develop → main

**Version Bumping**
- Patch (1.1.x): bug fixes
- Minor (1.x.0): new features
- Major (x.0.0): breaking changes or major rewrites

---

## Verification Plan

After creating all documents:

1. **Content accuracy** — Verify every module, table, hook, and endpoint mentioned actually exists in the code
2. **CLAUDE.md effectiveness** — Start a fresh AI conversation and ask it to add a feature using only the CLAUDE.md — does it have enough context?
3. **CONTRIBUTING.md usability** — Follow the "add a REST endpoint" guide yourself — does it work end-to-end?
4. **Cross-references** — Verify all links between documents resolve correctly
5. **Standards enforcement** — Run WP Plugin QA MCP audit on both plugins to establish baseline
6. **No doc rot** — Each document states when it should be updated (after schema changes, after new features, etc.)

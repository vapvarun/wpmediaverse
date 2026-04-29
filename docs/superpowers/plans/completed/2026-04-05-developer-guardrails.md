# Developer Guardrails & Documentation Suite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create 10 documents across Free and Pro plugins that establish coding standards, architecture reference, and developer guides — so any developer or AI can work on WPMediaVerse with full context.

**Architecture:** Documentation-only changes. No code modifications. All docs live in plugin root (CLAUDE.md) or docs/ directories. Pro docs reference Free docs to avoid duplication.

**Tech Stack:** Markdown files, verified against actual codebase.

**Spec:** `docs/superpowers/specs/2026-04-05-developer-guardrails-design.md`

---

## File Structure

### Free Plugin (`wpmediaverse/`)
- Create: `CLAUDE.md` — AI-first quick reference
- Create: `docs/ARCHITECTURE.md` — Full technical reference
- Create: `docs/CODING_STANDARDS.md` — Hard rules with enforcement
- Create: `docs/CONTRIBUTING.md` — Task guides for common operations
- Create: `docs/REFACTORING_ROADMAP.md` — Prioritized cleanup backlog
- Create: `docs/EXTENSION_GUIDE.md` — How to extend the plugin
- Create: `docs/SECURITY_CHECKLIST.md` — PR security review checklist
- Create: `docs/GIT_WORKFLOW.md` — Commit conventions and PR process

### Pro Plugin (`wpmediaverse-pro/`)
- Create: `CLAUDE.md` — Pro-specific rules and extension patterns
- Create: `docs/ARCHITECTURE.md` — Pro features and competition system

---

### Task 1: Free Plugin CLAUDE.md

**Files:**
- Create: `wpmediaverse/CLAUDE.md`

- [ ] **Step 1: Create CLAUDE.md**

Write the file with these sections (all data verified from codebase exploration):

```markdown
# WPMediaVerse

## Quick Facts
- Version: 1.1.0
- PHP: 8.1+ | WP: 6.4+
- Namespace: `WPMediaVerse\`
- Autoloading: PSR-4 via Composer
- Architecture: Service container + WordPress hooks
- Text domain: `wpmediaverse`
- Custom tables: 21 (prefixed `mvs_`)
- REST endpoints: 20 controllers, namespace `mvs/v1`
- Pro extension: Hooks into `do_action('mvs_loaded')`

## Module Map

| Namespace | Responsibility | Key Classes |
|-----------|---------------|-------------|
| `Core/` | Bootstrap, DI, migrations | Plugin, ServiceContainer, Migrator, Loader, TemplateLoader, TemplateHelpers |
| `Admin/` | Admin pages (8 classes) | SettingsPage, OverviewPage, ModerationQueue, StatsPage, LogViewerPage, MediaListPage, SetupWizard, CollectionMetaBox |
| `REST/Controller/` | 20 REST controllers | Media, Album, Collection, Bulk, Reaction, Comment, Favorite, Stats, Tag, Moderation, Access, SignedUrl, Follow, Notification, User, Report, Activity, Profile, Messaging, Conversation |
| `Services/` | Business logic (16 services) | UploadService, StorageService, PrivacyService, AlbumService, CollectionService, StoryService, AIService, ModerationService, CacheService, StatsService, AccessRulesService, SignedUrlService, WatermarkService, MediaMeta, ProfileService, LoggerService |
| `Social/` | Social features (9 services) | ReactionService, CommentService, FavoriteService, FollowService, NotificationService, ReportService, ActivityService, MentionService, ShareService |
| `Integrations/` | External plugin bridges | BuddyPressIntegration, WebhookService |
| `PostTypes/` | Custom post types | Album (`mvs_album`), Collection (`mvs_collection`) |
| `Taxonomies/` | Custom taxonomies | MediaTag (`mvs_media_tag`), MediaCategory (`mvs_media_category`) |
| `Blocks/` | Gutenberg blocks | BlockRegistrar |
| `Shortcodes/` | Legacy shortcodes | Shortcodes |
| `CLI/` | WP-CLI commands | Commands |
| `Messaging/` | DM system | MessagingService, MessagingController, ConversationController |
| `Capabilities/` | Custom capabilities | MediaCapabilities (`manage_mvs_media`, `manage_mvs_access`, `manage_mvs_settings`, `moderate_mvs_media`) |

## Service Container Keys

Access via `Plugin::container()->get('key')`:

| Key | Class | Registered at |
|-----|-------|---------------|
| `storage` | StorageService | Plugin.php:225 |
| `upload` | UploadService | Plugin.php:231 |
| `admin.overview` | OverviewPage | Plugin.php:239 |
| `admin.settings` | SettingsPage | Plugin.php:246 |
| `privacy` | PrivacyService | Plugin.php:253 |
| `reactions` | ReactionService | Plugin.php:260 |
| `comments` | CommentService | Plugin.php:267 |
| `favorites` | FavoriteService | Plugin.php:274 |
| `mentions` | MentionService | Plugin.php:281 |
| `shares` | ShareService | Plugin.php:288 |
| `stats` | StatsService | Plugin.php:295 |
| `albums` | AlbumService | Plugin.php:302 |
| `collections` | CollectionService | Plugin.php:309 |
| `stories` | StoryService | Plugin.php:316 |
| `ai` | AIService | Plugin.php:325 |
| `moderation` | ModerationService | Plugin.php:342 |
| `admin.moderation` | ModerationQueue | Plugin.php:351 |
| `admin.stats` | StatsPage | Plugin.php:359 |
| `admin.logs` | LogViewerPage | Plugin.php:365 |
| `admin.setup_wizard` | SetupWizard | Plugin.php:372 |
| `admin.collection_metabox` | CollectionMetaBox | Plugin.php:379 |
| `access_rules` | AccessRulesService | Plugin.php:388 |
| `signed_urls` | SignedUrlService | Plugin.php:395 |
| `watermark` | WatermarkService | Plugin.php:402 |
| `integration.buddypress` | BuddyPressIntegration | Plugin.php:411 |
| `integration.webhooks` | WebhookService | Plugin.php:420 |
| `cache` | CacheService | Plugin.php:429 |
| `follows` | FollowService | Plugin.php:455 |
| `notifications` | NotificationService | Plugin.php:462 |
| `reports` | ReportService | Plugin.php:471 |
| `activity` | ActivityService | Plugin.php:478 |
| `profile` | ProfileService | Plugin.php:487 |

## Coding Rules

1. **Max file size: 500 lines** — Split before adding code to oversized files
2. **Max method size: 50 lines** — Forces single responsibility
3. **Database queries:** Always use `$wpdb->prepare()`. Plan migration to repository pattern.
4. **Admin HTML:** Template files in `templates/admin/`, never inline `echo` in methods
5. **Hook names:** `mvs_` prefix, snake_case (e.g., `mvs_media_uploaded`)
6. **REST endpoints:** Extend `WP_REST_Controller` with schema + `permission_callback`
7. **Security:** Nonce + capability check on every write operation
8. **Error handling:** Use `WP_Error` or `LoggerService`, never silent failures
9. **i18n:** All user-facing strings wrapped in `__()` / `esc_html__()`
10. **Pro boundary:** Pro must never import Free classes directly — use service container or hooks

## Known Debt (Do Not Worsen)

| File | Lines | Status |
|------|-------|--------|
| `Integrations/BuddyPressIntegration.php` | 2,811 | Needs split into 4-5 classes |
| `Admin/SettingsPage.php` | 2,401 | Needs split into 3 classes + templates |
| `Messaging/MessagingService.php` | 1,606 | Needs split into 2 classes |
| `Core/Plugin.php` | 1,208 | Acceptable for bootstrap — do not grow |
| `REST/Controller/MediaController.php` | 1,105 | Needs query extraction to repository |
| `Messaging/MessagingController.php` | 803 | Needs query extraction |

See `docs/REFACTORING_ROADMAP.md` for priorities and approach.

## Testing

- **Framework:** PHPUnit 9.6 with `yoast/phpunit-polyfills`
- **Run:** `composer test` or `./vendor/bin/phpunit`
- **Config:** `phpunit.xml.dist`
- **Tests:** 11 files in `tests/unit/` (~10% coverage, target: 50%+)
- **Covered:** Reactions, Favorites, Comments, Follow, Capabilities, MediaMeta, Privacy, CLI, REST basics
- **NOT covered:** BuddyPress integration, messaging, settings, storage, AI, moderation, admin pages

## Build & Release

- **Build:** `npx grunt dist` (minifies CSS/JS, creates dist ZIP)
- **WPCS:** `composer run phpcs`
- **PHPStan:** `composer run phpstan`
- **QA:** WP Plugin QA MCP tools (`wppqa_audit_plugin`, `wppqa_run_code_checks`)
- **Dist:** `.distignore` excludes dev files; `dist/` contains release-ready code

## Recent Changes

| Date | Change | Files |
|------|--------|-------|
| (Updated after each significant commit) | | |
```

- [ ] **Step 2: Verify module map accuracy**

Run: `ls wpmediaverse/includes/` and spot-check 3-4 directories match CLAUDE.md module map.

- [ ] **Step 3: Verify service container keys**

Run: `grep -n "register(" wpmediaverse/includes/Core/Plugin.php | head -35` and confirm keys match.

- [ ] **Step 4: Commit**

```bash
git add wpmediaverse/CLAUDE.md
git commit -m "docs: add CLAUDE.md with module map, service keys, and coding rules"
```

---

### Task 2: Free Plugin docs/ARCHITECTURE.md

**Files:**
- Create: `wpmediaverse/docs/ARCHITECTURE.md`

- [ ] **Step 1: Create ARCHITECTURE.md**

Write the file with these sections. Data comes from the codebase exploration results above.

**Section 1: Plugin Lifecycle**
```
wpmediaverse.php → defines constants (MVS_VERSION, MVS_PLUGIN_DIR, etc.)
  → Plugin::init()
    → load_plugin_textdomain()
    → MediaCapabilities::add_caps() (version-gated)
    → Migrator::run() (version-based, CURRENT_VERSION = 9)
    → ServiceContainer setup (32 lazy-loaded services)
    → register_types() via init hook (Album CPT, Collection CPT, MediaTag tax, MediaCategory tax)
    → register_admin_menu() via admin_menu hook (priority 5)
    → BlockRegistrar::init()
    → Shortcodes::init()
    → REST controllers via rest_api_init (20 controllers)
    → BuddyPressIntegration (if BP active)
    → WebhookService
    → LoggerService auto-hooks
    → CacheService
    → TemplateLoader
    → Social services (Follow, Notifications, Reports, Activity)
    → Messaging services
    → do_action('mvs_loaded') ← Pro hooks in here
```

**Section 2: Database Schema** — Include ALL 21 tables from the exploration data above with columns, types, indexes. Group by domain:
- Core media: `mvs_media_index`, `mvs_media_meta`, `mvs_media_stats`, `mvs_media_views`
- Albums: `mvs_album_items`
- Social: `mvs_reactions`, `mvs_favorites`, `mvs_follows`, `mvs_mentions`, `mvs_notifications`
- Moderation: `mvs_reports`, `mvs_blocks`, `mvs_activity`
- Access: `mvs_access_rules`, `mvs_access_grants`, `mvs_transactions`
- Messaging: `mvs_conversations`, `mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions`
- System: `mvs_error_log`

**Section 3: REST API Map** — Include ALL endpoints from the exploration data. Group by resource:
- Media (`/mvs/v1/media/*`) — 6 routes
- Albums (`/mvs/v1/albums/*`) — 6 routes
- Collections (`/mvs/v1/collections/*`) — 3 routes
- Social (`reactions`, `favorites`, `comments`, `follows`) — 10 routes
- Users (`/mvs/v1/users/*`, `/mvs/v1/me/*`) — 12 routes
- Moderation (`/mvs/v1/moderation/*`) — 6 routes
- Content (`tags`, `feed`, `activity`) — 6 routes
- Access/Signing (`rules`, `grants`, `signed-url`) — 7 routes

For each: HTTP method, route, permission, controller.

**Section 4: Hook Reference** — Include ALL mvs_* hooks from exploration. Group by lifecycle:
- Plugin init: `mvs_loaded`, `mvs_ai_providers`
- Media lifecycle: `mvs_media_deleted`, `mvs_reaction_toggled`, `mvs_reaction_added`, `mvs_favorite_toggled`
- Social: `mvs_tags_merged`
- Admin: `mvs_comment_edit_window`, `mvs_collection_response`
- Cron: `mvs_prune_logs`

**Section 5: Template System** — Document TemplateLoader and TemplateHelpers usage.

**Section 6: Free → Pro Boundary** — Document `mvs_loaded` hook, service container access pattern, filter hooks Pro uses.

- [ ] **Step 2: Verify 3 table schemas against Migrator.php**

Read `includes/Core/Migrator.php` and confirm `mvs_media_index`, `mvs_reactions`, and `mvs_conversations` schemas match.

- [ ] **Step 3: Verify 3 REST routes against controller files**

Read `includes/REST/Controller/MediaController.php`, `AlbumController.php`, and `FollowController.php` — confirm routes match.

- [ ] **Step 4: Commit**

```bash
git add wpmediaverse/docs/ARCHITECTURE.md
git commit -m "docs: add ARCHITECTURE.md with schema, REST map, hooks, and lifecycle"
```

---

### Task 3: Free Plugin docs/CODING_STANDARDS.md

**Files:**
- Create: `wpmediaverse/docs/CODING_STANDARDS.md`

- [ ] **Step 1: Create CODING_STANDARDS.md**

Content from spec Section 4. Include:

1. **Hard Rules Table** — 12 rules with category, rule, limit, enforcement method
2. **Anti-Patterns Section** — 5 anti-patterns with bad example and correct approach:
   - God class (>500 lines) → split by responsibility
   - Inline SQL (raw $wpdb) → use prepare() or repository
   - Copy-paste (same logic 2+ places) → extract to shared service
   - Tight coupling (Pro importing Free class) → use service container
   - Silent failure (catch with no log) → add LoggerService call
3. **Good Patterns Section** — Show existing good examples from codebase:
   - ServiceContainer usage (Plugin.php:225-487)
   - REST controller pattern (AlbumController.php extending WP_REST_Controller)
   - LoggerService auto-hooks (LoggerService.php:27-102)
   - N+1 prevention (CommentService.php:220, NotificationService.php:180)

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/CODING_STANDARDS.md
git commit -m "docs: add CODING_STANDARDS.md with rules, anti-patterns, and good examples"
```

---

### Task 4: Free Plugin docs/CONTRIBUTING.md

**Files:**
- Create: `wpmediaverse/docs/CONTRIBUTING.md`

- [ ] **Step 1: Create CONTRIBUTING.md**

Content from spec Section 5. Include 7 step-by-step guides:

1. **How to add a new feature (Free)** — 8 steps from service to test
2. **How to add a REST endpoint** — 6 steps with WP_REST_Controller pattern
3. **How to fix a bug** — 6 steps from reproduce to QA
4. **How to add a competition type (Pro)** — 7 steps
5. **How to add a storage driver (Pro)** — 5 steps
6. **How to add an AI provider (Pro)** — 4 steps
7. **How to add a migration importer (Pro)** — 5 steps

Each guide references actual file paths and existing examples.

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/CONTRIBUTING.md
git commit -m "docs: add CONTRIBUTING.md with 7 task guides for common operations"
```

---

### Task 5: Free Plugin docs/REFACTORING_ROADMAP.md

**Files:**
- Create: `wpmediaverse/docs/REFACTORING_ROADMAP.md`

- [ ] **Step 1: Create REFACTORING_ROADMAP.md**

Content from spec Section 6. Three priority tiers:

**Priority 1 — Foundation:**
- 1.1: Extract MediaRepository (842 scattered $wpdb → central data access)
- 1.2: Split BuddyPressIntegration (2,811 lines → 5 classes: ActivitySync, NotificationBridge, ProfileTab, GroupTab, ActivityForm)
- 1.3: Split SettingsPage (2,401 lines → SettingsManager + SettingsRenderer + templates)

**Priority 2 — Pro stabilization:**
- 2.1: Split MigrationPage (1,776 lines → MigrationDetector + MigrationBatcher + template)
- 2.2: Extract AbstractBatchImporter (3 copy-pasted CLI importers → base + 3 subclasses)
- 2.3: Split MessagingService (1,606 lines → MessagingPrivacy + MessagingService CRUD)

**Priority 3 — Quality infrastructure:**
- 3.1: Add interfaces for Free/Pro boundary (includes/Contracts/ directory)
- 3.2: Increase test coverage (10% → 50%+)
- 3.3: Admin page template extraction (inline HTML → templates/admin/)
- 3.4: Extract Plugin.php bootstrappers in Pro (includes/Features/ directory)

Each item: current state, target state, files affected, definition of done.

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/REFACTORING_ROADMAP.md
git commit -m "docs: add REFACTORING_ROADMAP.md with 10 prioritized items"
```

---

### Task 6: Free Plugin docs/EXTENSION_GUIDE.md

**Files:**
- Create: `wpmediaverse/docs/EXTENSION_GUIDE.md`

- [ ] **Step 1: Create EXTENSION_GUIDE.md**

Guides for extending WPMediaVerse from external plugins:

1. **Hooking into Plugin Lifecycle** — `mvs_loaded`, `mvs_pro_loaded` hooks
2. **Adding a Storage Driver** — `StorageDriverInterface`, `mvs_storage_driver` filter, required methods
3. **Adding an AI Provider** — `AIProviderInterface`, `mvs_ai_providers` action, required methods
4. **Adding Custom REST Endpoints** — `rest_api_init` hook, namespace conventions
5. **Extending Admin UI** — `mvs_moderation_tabs`, `mvs_stats_tabs` filters for tab injection
6. **Frontend Hooks** — `mvs_before_explore_grid`, `mvs_dashboard_tabs`, `mvs_dashboard_panels`
7. **Activity & Notification Hooks** — `mvs_activity_types` filter, `mvs_reaction_added`, etc.

Each guide: hook name, where it fires, example code snippet, what to return.

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/EXTENSION_GUIDE.md
git commit -m "docs: add EXTENSION_GUIDE.md with 7 extension patterns"
```

---

### Task 7: Free Plugin docs/SECURITY_CHECKLIST.md

**Files:**
- Create: `wpmediaverse/docs/SECURITY_CHECKLIST.md`

- [ ] **Step 1: Create SECURITY_CHECKLIST.md**

Content from spec Section 7. Checklist format with 6 categories:

1. **Input Layer** (3 checks) — sanitization, file upload validation, JSON validation
2. **Authentication & Authorization** (4 checks) — nonces, capabilities, permission callbacks, custom caps
3. **Database** (3 checks) — prepare(), no interpolation, esc_like()
4. **Output** (3 checks) — esc_html, esc_attr, esc_url, wp_kses_post
5. **AJAX/REST** (3 checks) — referer checks, no data leaks, rate limiting
6. **File System** (3 checks) — no user paths, .htaccess protection, temp cleanup

Plus automated checks section referencing WP Plugin QA MCP tools.

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/SECURITY_CHECKLIST.md
git commit -m "docs: add SECURITY_CHECKLIST.md with 19-point PR review checklist"
```

---

### Task 8: Free Plugin docs/GIT_WORKFLOW.md

**Files:**
- Create: `wpmediaverse/docs/GIT_WORKFLOW.md`

- [ ] **Step 1: Create GIT_WORKFLOW.md**

Content from spec Section 8:

1. **Branch Strategy** — main (stable), develop (integration), feature/*, fix/*, release/*
2. **Commit Message Convention** — `<type>(<scope>): <subject>` format with types and scopes
3. **PR Process** — 10-step workflow from branch to merge
4. **Version Bumping** — Semver: patch, minor, major with examples

- [ ] **Step 2: Commit**

```bash
git add wpmediaverse/docs/GIT_WORKFLOW.md
git commit -m "docs: add GIT_WORKFLOW.md with branch strategy, commit conventions, and PR process"
```

---

### Task 9: Pro Plugin CLAUDE.md

**Files:**
- Create: `wpmediaverse-pro/CLAUDE.md`

- [ ] **Step 1: Create CLAUDE.md**

Write Pro-specific CLAUDE.md with:

1. **Quick Facts** — Version, namespace, text domain, license
2. **How Pro Extends Free** — 5 extension patterns (mvs_loaded, free_service(), tab filters, storage filter, AI providers)
3. **IMPORTANT boundary rule** — Never import Free classes directly. Exception: MediaMeta (earmarked for interface)
4. **Module Map** — All Pro namespaces with classes and feature toggles (from exploration data)
5. **Feature Toggles** — `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`
6. **Free Service Keys Used by Pro** — `reports` (MessagingService.php:50), `follows` (MessagingService.php:93), `notifications` (NotificationListener.php:80)
7. **Coding Rules** — Same as Free plus: settings toggles required, CLI importers share base class, admin pages use templates
8. **Known Debt** — 8 god classes with line counts and refactoring targets
9. **References** — Links to Free plugin docs (ARCHITECTURE, CODING_STANDARDS, CONTRIBUTING, SECURITY_CHECKLIST, GIT_WORKFLOW)

- [ ] **Step 2: Verify feature toggles**

Run: `grep -n "get_option.*enabled" wpmediaverse-pro/includes/Core/Plugin.php` and confirm toggles match.

- [ ] **Step 3: Commit**

```bash
git add wpmediaverse-pro/CLAUDE.md
git commit -m "docs: add Pro CLAUDE.md with extension patterns, module map, and boundaries"
```

---

### Task 10: Pro Plugin docs/ARCHITECTURE.md

**Files:**
- Create: `wpmediaverse-pro/docs/ARCHITECTURE.md`

- [ ] **Step 1: Create ARCHITECTURE.md**

Write Pro architecture doc with:

1. **Plugin Lifecycle** — Full init() order from exploration data (34 blocks, lines 78-429)
2. **Database Schema** — All Pro tables from exploration:
   - Quota: `mvs_quota_packages`, `mvs_credit_log`
   - Analytics: `mvs_play_events`
   - Messaging: `mvs_conversations`, `mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions` (note: also in Free Migrator)
   - Competition: `mvs_competitions`, `mvs_competition_entries`, `mvs_competition_matches`, `mvs_competition_votes`
   - Boosts: `mvs_boosts`
3. **REST API Map** — All Pro endpoints grouped by feature:
   - Quota (3 routes)
   - Privacy (4 routes)
   - Video (5 routes)
   - Captions (5 routes)
   - Transcoding (5 routes)
   - Analytics (4 routes)
   - Battles (7 routes)
   - Challenges (10 routes)
   - Tournaments (9 routes)
   - Boosts (2 routes)
   - Messaging (5 routes)
   - Competition Summary (1 route)
4. **Hook Reference** — All 20 Pro hooks grouped by feature:
   - Cron/Scheduled (8 hooks)
   - Analytics (2 filters + 1 action)
   - Membership adapters (6 actions)
   - Messaging (3 filters + 1 action)
   - Admin UI (4 filters)
   - Frontend (3 actions/filters)
5. **Competition System Architecture** — How battles, challenges, and tournaments share the unified `mvs_competitions` table with type-specific JSON settings
6. **Video Pipeline** — Transcode flow: upload → queue → FFmpeg → S3/local → cleanup
7. **Quota System** — Package model, credit log, membership adapter pattern

- [ ] **Step 2: Verify competition table schema**

Read `wpmediaverse-pro/includes/Core/Migrator.php` and confirm `mvs_competitions` columns match.

- [ ] **Step 3: Commit**

```bash
git add wpmediaverse-pro/docs/ARCHITECTURE.md
git commit -m "docs: add Pro ARCHITECTURE.md with schema, REST map, hooks, and system guides"
```

---

### Task 11: Final Verification

- [ ] **Step 1: Cross-reference check**

Verify all cross-document links:
- Free CLAUDE.md → docs/ARCHITECTURE.md (service container, schema references)
- Free CLAUDE.md → docs/REFACTORING_ROADMAP.md (known debt link)
- Pro CLAUDE.md → Free docs/* (5 references)

- [ ] **Step 2: Run WP Plugin QA baseline**

Run `wppqa_audit_plugin` on both plugins to establish a quality baseline referenced in the docs.

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "docs: complete developer guardrails suite — 10 documents across Free and Pro"
```

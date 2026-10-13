# WPMediaVerse — AI Quick Reference

> **TRUST ORDER:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) (code-verified state) → [`CAPABILITIES.md`](CAPABILITIES.md) (what it does, in buyer language) → the code itself (wins on any disagreement). Dated files under `qa/runs/`, `audit/runs/` and `plan/` are history - verify before trusting.
>
> **Manifest refresh: agent-enumeration-only.** `write-manifest.mjs` cannot see this plugin's registration patterns (SettingsRegistrar, ServiceContainer::register(), MediaCapabilities, PostTypes\Album::register(), CLI subcommands as methods) and would zero out settings/services/wp_cli/capabilities/post_types/taxonomies. Refresh by targeted delta against ground-truth grep, never by committing generator output.
>
> **READ FIRST:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) is a small index - load it first. The full inventory is in [`audit/manifests/manifest.json`](audit/manifests/manifest.json), with detail files [`manifest.rest.json`](audit/manifests/manifest.rest.json), [`manifest.hooks.json`](audit/manifests/manifest.hooks.json), [`manifest.tables.json`](audit/manifests/manifest.tables.json). Pro audit mirror: [`audit/pro/`](audit/pro/) (manifests + journeys only). Bug-finder baseline: newest `audit/runs/*wppqa-baseline-SUMMARY.md` (local-CI stage 2.4 fails if it is >14 days old). Refresh: `/wp-plugin-onboard --refresh`.
>
> **Counts live in the code, not in this file.** A bare number here rots. Ask the code:
>
> | What | Command |
> |---|---|
> | REST endpoints | `jq '.rest.endpoints \| length' audit/manifests/manifest.rest.json` |
> | REST controllers | `ls includes/REST/Controller/*.php \| wc -l` |
> | Hooks fired | `jq '.hooks_fired \| length' audit/manifests/manifest.hooks.json` |
> | Plugin AJAX actions | `grep -rhoE "add_action\( *'wp_ajax_mvs_[a-z_]+'" includes/ \| sort -u \| wc -l` (the looser `grep -rho "wp_ajax_mvs_[a-z_]*"` also counts a bare prefix inside a comment) |
> | Admin page registrations | `grep -rn 'add_menu_page(\|add_submenu_page(' includes/ \| grep -vc 'function \|\*'` (call sites; the manifest's `admin_pages` counts rendered surfaces: call sites plus CPT menu entries and settings tabs) |
> | Settings | `grep -rhA2 'register_setting(' includes/Admin/Settings/ \| grep -o "'mvs_[a-z_0-9]*'" \| sort -u \| wc -l` (literal options only; some `register_setting()` calls are foreach loops over class constants, so the manifest's full expansion is higher; a naive `grep -c` also matches doc comments) |
> | Custom tables | `grep -c 'CREATE TABLE' includes/Core/Migrator.php` (a comment in the file also matches; use the `-o` one-liner under Custom Tables) |
> | Registered blocks | `BlockRegistrar::BLOCKS` / `ls src/blocks/*/block.json \| wc -l` (some blocks are Interactivity-only and not registered by `BlockRegistrar`) |
> | Container services | `grep -A1 'container->register(' includes/Core/Plugin.php \| grep -o "'[a-z_.]*'," \| sort -u \| wc -l` |
> | WP-CLI subcommands | `grep -c 'public function ' includes/CLI/Commands.php` |
> | Migrator version | `grep CURRENT_VERSION includes/Core/Migrator.php` |
>
> Where a number here and a number in `manifest.summary.json` differ, the summary's `counts_note` says which unit it counted. Read it before assuming drift.

## Quick Facts

| Key | Value |
|-----|-------|
| PHP | `Requires PHP: 8.1` (supported range 8.1-8.5; no syntax newer than 8.1) |
| WordPress | >= 6.5 |
| Namespace | `WPMediaVerse\` |
| Autoload | Hand-written PSR-4 in `wpmediaverse.php` (`WPMediaVerse\` → `includes/`). **Runtime never loads Composer.** |
| Runtime deps | Committed under `libs/` (Action Scheduler, EDD SL SDK) - see `libs/README.md` |
| `vendor/` | Dev and build tooling ONLY. Gitignored, not in the release zip, never loaded at runtime. |
| Text Domain | `wpmediaverse` |
| Custom Tables | Prefixed `mvs_` |
| REST Controllers | `includes/REST/Controller/` (all extend `WP_REST_Controller` except `AccountController`, a plain class), plus `Messaging\MessagingController`. Namespace `mvs/v1`. |
| Pro Extension Hook | `mvs_loaded` (fires with `ServiceContainer`) |
| Build | `npx grunt dist` |
| Entry Point | `wpmediaverse.php` -> `Plugin::init()` |
| Admin Slug | `wpmediaverse` |

---

## Module Map

Every namespace under `includes/` is listed. `find includes -name '*.php' | sort` is the check.

| Namespace | Responsibility | Key Classes |
|-----------|---------------|-------------|
| `Core\` | Bootstrap, DI container, migrations, templates, settings helper, read-side URL facade, type vocabularies | `Plugin`, `ServiceContainer`, `Migrator`, `Activator`, `Deactivator`, `TemplateLoader`, `TemplateHelpers` (+`TemplateHelpersInterface`), `Abilities`, `SettingsHelper`, `MediaUrl`, `MediaTypes`, `DocumentTypes`, `DashboardSections`, `Dates`, `TemplateVersions`, `PresetActivation` (+ `Loader`, deprecated, never used, removal 4.0.0) |
| `Admin\` | WP admin pages, moderation queue, member moderation | `OverviewPage`, `StatsPage`, `ModerationQueue`, `MemberModeration`, `ReportsPage`, `LogViewerPage`, `SetupWizard`, `CollectionMetaBox`, `MediaListPage`, `DocumentListPage`, `IntegrationsPage`, `TagManagementPage`, `AlbumMetaBox`, `PrivateCommunityDefault` |
| `Admin\Settings\` | Settings page (focused classes, one registrar per settings group) | `SettingsPage`, `SettingsRegistrar`, `GeneralSettingsRegistrar`, `DisplaySettingsRegistrar`, `AiSettingsRegistrar`, `MessagingSettingsRegistrar`, `AppSettingsRegistrar`, `SettingsSaveGuard`, `FieldRenderer`, `PermissionsManager`, `Sanitizers` |
| `REST\Controller\` | REST API endpoints | `MediaController`, `AlbumController`, `CollectionController`, `BulkController`, `ReactionController`, `CommentController`, `FavoriteController`, `StatsController`, `TagController`, `ModerationController`, `SignedUrlController`, `FollowController`, `NotificationController`, `UserController`, `ReportController`, `ActivityController`, `ProfileController`, `AdminController`, `AuthController`, `ConfigController`, `InterestsController`, `TransactionController`, `AccountController`, `DeviceController` |
| `REST\` | Middleware, gates, pagination | `RateLimiter`, `RestGate`, `RestGuards`, `CommunityPrivacyGate`, `Pagination` |
| `Services\` | Business logic, storage, AI, caching, URL signing, variant pipeline, poster generation | `UploadService`, `StorageService`, `StorageRouter`, `MediaVariantWriter`, `VariantSpec`, `PosterService`, `FilenameStrategy`, `ImageOptimizationService`, `PrivacyService`, `AlbumService`, `CollectionService`, `AIService`, `OpenAIProvider`, `ModerationService`, `StatsService`, `AdminAggregatesService`, `SignedUrlService`, `DirectDelivery`, `ServerFileOffload`, `WatermarkService`, `MediaFileRotator`, `Mp4Faststart`, `CacheService`, `LoggerService`, `EmailService`, `StorageLimitService`, `GDPRService`, `HealthCheckService`, `ProfileService`, `TransactionService`, `LocalDriver`, `CloudOps`, `StorageCleanupService`, `StorageRepairService`, `CptIdCollisionService`, `AccountDeletionService`, `UserDeletionService`, `ViewRetentionService` (+ `StorageDriverInterface`, `AIProviderInterface`) |
| `Social\` | Social interactions (reactions, comments, follows, push, suggestions) | `ReactionService`, `CommentService`, `FavoriteService`, `MentionService`, `ShareService`, `FollowService`, `NotificationService`, `CommunityNotificationContract`, `PushService`, `ReportService`, `ActivityService`, `SuggestionService` |
| `Integrations\` | Third-party platform bridges | `WebhookService` |
| `Integrations\BuddyPress\` | BuddyPress integration | `BuddyPressManager`, `BaseBPTabIntegration`, `ActivitySyncIntegration`, `ActivityContentIntegration`, `ActivityFormIntegration`, `ActivityMediaLinkage`, `ActivityPrivacyFilter`, `ProfileTabIntegration`, `GroupTabIntegration`, `NotificationIntegration`, `MediaDisplayHelper` |
| `Integrations\BPVerifiedMember\` | Verified-member badge bridge | `BadgeIntegration` |
| `Integrations\Companions\` | Companion-plugin registry + installer | `CompanionRegistry`, `CompanionInstaller` |
| `Auth\` | App-password / OAuth-style app connect | `AppConnect`, `AppAuthorizeAccess`, `AppCredentials` |
| `Privacy\` | GDPR export/erase maps and purger | `MemberDataMap`, `MemberPurger` |
| `Media\` | Object↔media linkage | `ObjectMediaLinkage` |
| `Cert\` | Behavioural cert runner (`wp mvs cert`) | `CertCommand`, `CertRunner` |
| `PostTypes\` | Custom post type registration, and keeping non-public albums and collections out of core's archive, feed and sitemap | `Album`, `Collection`, `PublicListing` |
| `Taxonomies\` | Custom taxonomy registration | `MediaTag`, `MediaCategory` |
| `Blocks\` | Gutenberg block registration | `BlockRegistrar`, `StandardAttributes`, `MVS_CSS` |
| `Shortcodes\` | Legacy shortcode support | `Shortcodes` |
| `CLI\` | WP-CLI commands | `Commands` |
| `Messaging\` | Direct messaging engine + REST routes | `MessagingService`, `MessagingController`, `NotificationListener`, `ChatFiles`, `RestPollingTransport`, `TransportInterface` |
| `Repository\` | Central data access layer | `MediaRepository` (+`MediaRepositoryInterface`), `MediaIntegrityRepository`, `MediaSpaceRepository`, `MessageRepository` |
| `Capabilities\` | Role/capability management | `MediaCapabilities` |

**Gone, do not reference:** `Services\StoryService` and `src/blocks/story-viewer` (Stories live in Pro, `WPMediaVersePro\Stories\StoryService`); `Services\AccessRulesService`, `REST\Controller\AccessController` and the `access_rules` container key (per-media access rules were removed in 2.6.0: privacy plus sharing is the whole access model); `Services\TelemetryService` and the `telemetry` key.

**Where a shared rule lives** (one owner each; extend these, do not re-implement):

| Rule | Owner |
|------|-------|
| One tile per gallery, badge count, lightbox members, listing filter | `MediaRepository::gallery_hidden_clause()`, `gallery_tile_counts()`, `gallery_members()`, `gallery_filter_args()` |
| Narrow a listing to a BuddyPress group (`GET /mvs/v1/media?group=`) | `MediaRepository::group_scope_clause()` |
| 200 instead of a soft 404 on a virtual page (never downgrades a 4xx) | `TemplateLoader::serve_as_found()` |
| Hide presence across a block; cast pin/mute/archive to booleans | `MessagingService::mask_blocked_presence()`, `cast_member_flags()` |
| Remove a deleted media comment's notifications | `NotificationService::on_comment_deleted()` via `MediaRepository::delete_comment_notifications()` |
| Mirror a media comment onto its BuddyPress activity post | `ActivitySyncIntegration` (link in comment meta `ACTIVITY_COMMENT_META`; `find_unlinked_mirror()` adopts an older unlinked copy) |

---

## Service Container Keys

Registered in `includes/Core/Plugin.php` via `register_services()` and `init_messaging()`.

**The key is the lookup, not a line number.** Find a registration by grepping the key: `grep -n "'storage_router'" includes/Core/Plugin.php`. Re-enumerate the whole table with
`grep -A1 "container->register(" includes/Core/Plugin.php | grep -o "'[a-z_.]*',"`.

| Key | Class |
|-----|-------|
| `storage` | `StorageService` |
| `upload` | `UploadService` |
| `storage_router` | `StorageRouter` |
| `variant_writer` | `MediaVariantWriter` |
| `poster` | `PosterService` |
| `admin.overview` | `OverviewPage` |
| `admin.settings` | `SettingsPage` |
| `admin.tags` | `TagManagementPage` |
| `privacy` | `PrivacyService` |
| `reactions` | `ReactionService` |
| `comments` | `CommentService` |
| `favorites` | `FavoriteService` |
| `transactions` | `TransactionService` |
| `mentions` | `MentionService` |
| `shares` | `ShareService` |
| `stats` | `StatsService` |
| `albums` | `AlbumService` |
| `collections` | `CollectionService` |
| `ai` | `AIService` (+ `OpenAIProvider`) |
| `moderation` | `ModerationService` |
| `admin.moderation` | `ModerationQueue` |
| `admin.reports` | `ReportsPage` (registered only when Pro is not active; Pro ships its own Reports screen) |
| `admin.member_moderation` | `MemberModeration` |
| `admin.private_default` | `PrivateCommunityDefault` |
| `admin.stats` | `StatsPage` |
| `admin.logs` | `LogViewerPage` |
| `admin.setup_wizard` | `SetupWizard` |
| `admin.album_metabox` | `AlbumMetaBox` |
| `admin.collection_metabox` | `CollectionMetaBox` |
| `admin.integrations` | `IntegrationsPage` |
| `admin_aggregates` | `AdminAggregatesService` |
| `account_deletion` | `AccountDeletionService` |
| `user_deletion` | `UserDeletionService` |
| `emails` | `EmailService` |
| `storage_limit` | `StorageLimitService` |
| `signed_urls` | `SignedUrlService` |
| `watermark` | `WatermarkService` |
| `integration.buddypress` | `BuddyPressManager` |
| `integration.bp_verified_member` | `BadgeIntegration` |
| `integration.bp_activity_linkage` | `ActivityMediaLinkage` |
| `integration.webhooks` | `WebhookService` |
| `cache` | `CacheService` |
| `image_optimization` | `ImageOptimizationService` |
| `follows` | `FollowService` |
| `notifications` | `NotificationService` |
| `push` | `PushService` |
| `reports` | `ReportService` |
| `activity` | `ActivityService` |
| `profile` | `ProfileService` |
| `storage_cleanup` | `StorageCleanupService` |
| `storage_repair` | `StorageRepairService` |
| `media_repository` | `MediaRepository` |
| `media_space_repository` | `MediaSpaceRepository` |
| `message_repository` | `MessageRepository` |
| `object_media` | `ObjectMediaLinkage` |
| `template_helpers` | `TemplateHelpers` |
| `messaging` | `MessagingService` (registered in `init_messaging()`) |

There is no `stories` key (Stories are in Pro). Non-container helpers: `Core\MediaUrl` is the single read-side URL facade for non-REST callers; `VariantSpec` is a value object consumed by the upload pipeline.

---

## Custom Tables

All prefixed with `{$wpdb->prefix}mvs_`. Defined in `includes/Core/Migrator.php` (`Migrator::CURRENT_VERSION`). Re-enumerate with
`grep -o 'CREATE TABLE[^(]*mvs_[a-z_]*' includes/Core/Migrator.php | sort -u`.

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
| `mvs_access_grants` | Named-person sharing grants (Pro Documents sharing, including expiring grants). `mvs_access_rules` is retired: `Migrator::RETIRED_TABLES`, dropped by migration v38. |
| `mvs_album_items` | Album-to-media mapping |
| `mvs_error_log` | Internal error/debug log |
| `mvs_conversations` | DM conversation threads |
| `mvs_conversation_participants` | Conversation membership |
| `mvs_messages` | Individual DM messages |
| `mvs_message_reactions` | Reactions on DM messages |
| `mvs_transactions` | Per-media-type usage ledger (`TransactionService`); not a payments ledger |
| `mvs_bp_activity_media` | BuddyPress activity-to-media mapping |
| `mvs_device_tokens` | The ONE push device registry (`/me/devices`). Pro's `/mvs-pro/v1/push/register-device` writes here too, and Pro's Expo sender reads it on `mvs_push_send`. |
| `mvs_media_spaces` | Links one document into more than one space without a copy (`Repository/MediaSpaceRepository.php`) |

---

## Coding Rules

This is the index. Every rule below links to its full spec in `qa/` where one exists. Add new rules here first (1-2 sentence canonical version), then write the detailed spec.

1. **Max file size: 500 lines.** Files above this are tech debt (see Known Debt below). Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §1.
2. **Max method size: 50 lines.** Extract helpers or delegate to services. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §1.
3. **Database queries: always `$wpdb->prepare()`.** No raw interpolation.
4. **Admin HTML: template files only.** Never inline `echo` of HTML or `<script>` in PHP classes; use `templates/admin/`. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §2–§3.
5. **Hook names: `mvs_` prefix, snake_case.** Example: `mvs_media_uploaded`, `mvs_ai_providers`. Spec: `qa/rules/NAMING-RULES.md` §5.
6. **REST: extend `WP_REST_Controller`.** Every endpoint must define `get_item_schema()` and `get_item_permissions_check()` / `permission_callback`.
7. **Security: nonce + capability on every write.** Use `wp_verify_nonce()` for admin forms, `permission_callback` for REST.
8. **Error handling: `WP_Error` or `LoggerService`.** No silent `return false` - log failures. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §5.
9. **i18n: all user-facing strings wrapped.** Use `__()`, `esc_html__()`, `esc_attr__()` with text domain `wpmediaverse`. Spec: `qa/rules/NAMING-RULES.md` §10.
10. **Pro boundary: never import Free classes directly.** Pro hooks into `mvs_loaded` and uses `ServiceContainer` - no `use WPMediaVerse\...` in Pro code. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §9.
11. **No silent render fallthrough.** Every `return;` inside a render path (block `render.php`, shortcode callback, template, admin list, widget) must be paired with a visible empty state. Use `TemplateHelpers::render_block_empty_state()` / `render_admin_empty_state()`. Bare returns are only acceptable in hook callbacks, cron handlers, and REST permission checks. Spec: `qa/rules/RENDER-STATE-RULES.md`.
12. **CSS file ownership.** BP rules live in `bp-integration.css` (scoped under `#buddypress`). `frontend.css` is for generic plugin frontend only. Admin rules in `admin.css`. Messaging in `messaging.css`. Block-specific in `src/blocks/*/style.css`. Every BP-touching integration enqueues both `mvs-frontend` and `mvs-bp-integration`. No duplicate class-vs-ID rules. No `!important` without a one-line comment explaining what theme rule it fights. No dead selectors (every `.mvs-*` / `#mvs-*` must have an emitter). Spec: `qa/rules/CSS-ORGANIZATION-RULES.md`. Locked in `qa/inventory/WHAT-TO-CHECK.md` regression row "BP CSS file ownership".
13. **Names don't lie.** Class names, hook names, CSS classes must match actual usage. A `.mvs-bp-X` class used outside BP is a bug; either rename or narrow usage. Spec: `qa/rules/NAMING-RULES.md`.
14. **Sibling classes with ≥50% duplicate method bodies share a base class.** At n=2 duplication is tolerable; at n=3 it must be extracted. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` §6.
15. **Debt tax.** No PR adds lines to files in the Known Debt table below. Every edit to a debt file must reduce its line count or extract code out, unless the PR body justifies the addition. Spec: `qa/rules/PROCESS-RULES.md` §3.
16. **Cache backend by cardinality.** Pick the storage by how many distinct keys a callsite produces, not by convenience. ≤ ~50 fixed keys (settings, global counters, feature flags) → `wp_options` or transients are fine. Anything keyed by `user_id` / `media_id` / `conversation_id` / any entity → custom `mvs_*` table. Never options. Never transients without a `wp_using_ext_object_cache()` guard. Per-request reuse → static array on the service class. Site-wide aggregate counts (total media, total views, storage size) MUST go through `Services\AdminAggregatesService` - raw `$wpdb->get_var` SUM/COUNT against `mvs_*` tables outside that service is a Rule 3 violation in `bin/coding-rules-check.sh`. Per-entity transients (key contains `$user_id` etc.) is a Rule 4 violation. Spec: `qa/rules/PHP-ORGANIZATION-RULES.md` (performance section).
17. **Never modify site navigation via code.** Creating pages on activation is fine (they back features), but detach `_wp_auto_add_pages_to_menu` around the `wp_insert_post` calls so menus with WP's auto_add option are never edited - see `Activator::create_pages()`. Hooking `wp_nav_menu_items` / calling `wp_update_nav_menu_item` to inject items is forbidden: a code-injected item can't be removed in Appearance → Menus and surprises every site running the plugin. Feature discoverability belongs in plugin-owned surfaces (dashboard tabs, banners), never the site's menus.
18. **Three entry points per data store - frontend, backend, API.** Every `mvs_*` table / data feature must be reachable through all three: (1) **frontend UX** that lets members use it, (2) **backend/admin UI** that lets the site owner see and manage it, (3) **REST API** that populates/reads it. A table created with a Migrator bump (even seeded) but missing any of the three is a HALF-COOKED feature that ships dead weight. When adding a table or feature: wire all three in the same release, or document the intentional exception in the manifest (e.g., internal tables like `mvs_error_log` are admin+internal only). Every audit and every bug fix checks all three entry points; a fix that lands on one surface and misses the others violates this rule at fix time. **The API entry point is MANDATORY for MediaVerse, never an exception: a native mobile APP is planned, so every member-facing feature must be fully drivable through `mvs/v1` REST alone** - complete CRUD, auth that works outside the cookie/nonce browser context, consistent response shapes, honest pagination. A feature that only works through a PHP template or admin-ajax is app-blocking.
19. **No inline cosmetic CSS in markup.** Templates, block `render.php`, and HTML-echoing PHP must NOT carry cosmetic `style="…"` (color, background, margin/padding, font, border, box-shadow, text-align) or hardcoded hex. Move it to the owning stylesheet - block → `src/blocks/*/style.css`, BuddyPress → `bp-integration.css`, generic frontend → `frontend.css`, admin → `admin.css` - as a tokenized (`var(--mvs-*)`) class. **Reuse before you add:** most leaks were renderers re-inlining styles a tokenized class already provided (`.mvs-stat-value`, `.mvs-activity-audio-*`, `.mvs-leaderboard__*`); check the stylesheet first, never duplicate, never let markup and CSS diverge into dead selectors. **Allowed inline:** pure visibility toggles (`style="display:none"`), `var(--token, #fallback)` fallbacks, and instance custom properties (`style="--mvs-x:<?php … ?>"`). The CSS token-contract gate scans `.css` files only and is blind to inline `style=`. Enforced by `bin/template-style-check.sh` (local-CI stage 1.7). Spec: `qa/rules/CSS-ORGANIZATION-RULES.md`.
20. **A refusal is never a success response.** `wp_send_json_success()` must carry an outcome the caller asked for; a guard that declines the request returns `wp_send_json_error()` so the UI can say why. A refusal sent through a success envelope makes the JS take its success path and the button silently does nothing. Wiring audits prove the plumbing connects, not that the logic behind it is right. Enforced by `bin/coding-rules-check.sh` Rule 6, which greps success responses for refusal language.
21. **No exec-family call in shipped source.** `exec`, `shell_exec`, `proc_open`, `system`, `passthru`, `popen` - none of them, in either plugin. Security scanners flag them as a possible backdoor, and they match the CALL SITE IN THE SHIPPED FILE, not the runtime path: wrapping one in `if ( ffmpeg_available() )` changes nothing, and we cannot know which scanner a customer runs. If a feature genuinely needs a binary, it runs somewhere else: a remote service, the browser, or the site owner's own mu-plugin. The three shapes are in `docs/architecture/specs/2026-08-30-bunny-stream-video-encoding.md` §0. Enforced by `bin/coding-rules-check.sh` Rule 8 in BOTH plugins, mutation-tested.
22. **Apply a standard by RULE, never by enumerating the things it applies to.** When a decision holds for a whole class (hidden elements stay hidden, interactive controls clear the touch floor, a section with no panel here must navigate), express it as one rule keyed on what makes it true. A hand-maintained list of selectors, slugs or class names is a standard plus a memory test, and the memory loses. Failure shapes: a hand-kept list of `[hidden]` selectors missed new elements (fixed by one `[class*="mvs-"][hidden]` rule); a tab check by name broke when Pro added a section; a BP lightbox clone that strips `data-wp-*` needs a delegated handler on a stable class for every cloned control, not just the ones someone noticed. **The tell:** if adding a feature requires remembering to add it to a list somewhere else, the list is the bug. Ask the thing that makes the rule true (the rendered DOM, the section declaration, the design token) rather than restating its members.

    **Corollary - a threshold is read, never retyped.** A journey asserting "controls >= 40px" against a 44px token licenses the gap it was written to catch. Read `--mvs-touch-min` at runtime. Same for any contrast floor, page-size cap or timeout the plugin defines.
23. **When you fix a bug, sweep the class before you close it.** After fixing, grep for the shape (not the symptom) across BOTH plugins, and say in the commit what the sweep covered and what it did not. A fix with no sweep note is half a fix.
24. **Changing an overridable template bumps its `@version`.** Every template a theme can override (`templates/**` except `templates/admin/`) carries `@version` in its header; it moves to the release that ships a markup or variable change, and ONLY then. Site Health (`TemplateVersions` + `HealthCheckService::test_template_overrides()`) compares a theme's copy against it, so an unbumped change hides a stale copy from the owner. A template that stops reading or sending a request value (for example a renamed query arg) is such a change. Enforced by `bin/template-version-check.sh` (local-CI stage 1.9), mutation-tested both ways. Pro registers its folder through `mvs_template_roots`.
25. **Every feature has a catalog entry, written in the same commit.** `qa/inventory/FUNCTIONALITY-CATALOG.md` is the one file handed to testers and outside QA tools. A change that adds, removes or changes a feature, setting, screen, REST route, WP-CLI command or template adds or edits its entry (`MV-<AREA>-<NNN>`: who, where, setup, steps, expected, UX expectation, settings, edge cases) in the same commit, Free and Pro alike. Write facts read from the code, never a guess; if something cannot be confirmed, write a `Check:` line for the tester. A feature with no entry is a feature nobody tests.
26. **Comments say why, in present tense.** No ticket ids, dates or history in comments; the id goes in the commit message. Enforced by coding-rules Rule 9.

**Process meta:** how rules are added, checked, and retired - `qa/rules/PROCESS-RULES.md`.

---

## Production Rules (live customer sites - non-negotiable)

These rules protect the live customer sites. They are mechanically enforced via `bin/architecture-checks.sh` where possible; the rest are review-time hard gates. **No exceptions in patch releases.**

1. **NEVER hard-remove a public symbol in the release it is deprecated.** Minimum 2 major versions between `@deprecated since X.Y.Z` and deletion. Applies to: functions, classes, methods, hooks (`do_action`/`apply_filters`), REST routes, AJAX actions, options, meta keys, capabilities, WP-CLI commands, service container keys.
2. **NEVER rename a public identifier without an alias.** Option keys, capability names, hook names, REST routes, service keys - add a new one and alias the old one for ≥2 major versions. The old read path must continue to work.
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

> **Debt criterion:** A file lands here only when it has a CONCRETE structural problem - duplicate sibling classes, multiple unrelated responsibilities, a 350-line method, etc. Size alone is not a reason. For a plugin at this scale, files in the 1k-3k range are normal as long as each is focused on one responsibility. Split when a file's scope genuinely outgrows one class. Measure with `wc -l` before quoting a size.

| File | Issue |
|------|-------|
| `includes/Services/UploadService.php` | Mixes validation, type detection, storage routing and progress tracking, and keeps growing. `MediaVariantWriter` / `StorageRouter` / `PosterService` were extracted; a validator and a progress tracker were not. **OPEN.** |
| `includes/REST/Controller/MediaController.php::replace_file` | Very long method that orchestrates its own ingest instead of calling `UploadService::handle()`. Shared seams already cover the logic that can drift (`FilenameStrategy::pick()`, `apply_exif_orientation()`, `watermark->stamp_new_upload()`, `process_stored_file()`); what remains is the orchestration shell, and it genuinely differs (replace UPDATES an existing row and must not reset stats or mint a new media_id, `handle()` CREATES one). Collapsing it means teaching `handle()` an update mode, a behaviour change on the upload path that Production Rule 7 forbids in a patch. Debt tax applies: no new inline ingest logic here, extract a seam and call it from both sides. **OPEN.** |

**Large but NOT debt:** `MediaRepository.php`, `Plugin.php`, `MessagingService.php`, `MediaController.php`, `MessagingController.php`. They are large because their domain is large, not because responsibilities are tangled. Don't propose splits unless a real organizational problem surfaces; look for a genuine seam in `MediaRepository.php` when you are in it anyway.

**Baselined code-standard debt:** `audit/cleanup/code-standard-baseline.txt` lists existing violations of coding-rules Rules 9-11 (below). They pass until the code is touched.

**Debt tax (Coding Rule 15):** No PR adds lines to a file in the OPEN rows above. Every edit must reduce the line count or extract code out. Files in the "large but NOT debt" list have no debt tax; edit them normally.

---

## Testing

| Key | Value |
|-----|-------|
| Framework | PHPUnit 9.6 + yoast/phpunit-polyfills 2.x |
| Test dir | `tests/unit/` |
| Size | Ask the code: `ls tests/unit/*.php \| wc -l`, and `composer test:unit` for the suite totals. |
| Coverage | Not tracked. Measure with `phpunit --coverage-text` before quoting a number. |
| Run | `./vendor/bin/phpunit` or `composer test:unit` (also stage 2.4 of local-CI, see below) |
| Config | `phpunit.xml.dist` |

---

## Build & Release

| Command | Purpose |
|---------|---------|
| `npx grunt build` | RTL CSS, minify CSS/JS, generate .pot |
| `npx grunt dist` | Full build + clean dist/ + copy + ZIP |
| `npx grunt release` | CI check + dist |
| `npm run build` | Block assets (a bare `wp-scripts build` wipes tracked files in `build/`) |
| `composer run phpcs` | WPCS coding standards |
| `composer run phpstan` | Static analysis (baseline: `phpstan-baseline.neon`) |
| `./vendor/bin/phpunit` | Unit tests |

### Static Analysis Config

- `phpcs.xml` - WPCS ruleset
- `phpstan.neon.dist` - PHPStan config with WordPress stubs
- `phpstan-baseline.neon` - Known issues baseline

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
| `qa/` | All pre-release QA - runbooks, rules, inventory, audits, runs (Free + Pro) |
| `audit/` | Machine-derived audits - `manifests/`, `journeys/`, `runs/`, `cleanup/`, `conformance/`, `ux/`, dated `ux-audit-*.md`, `cert-*.json`. Pro mirror `audit/pro/` holds **manifests + journeys only**. |
| `marketing/` | Marketing copy and assets |

---

## Doc Map

All docs (Free + Pro) live in this plugin. Pro carries only its own `docs/standards/i18n.md`.

| Path | Contents |
|------|----------|
| `docs/architecture/MASTER_PLAN.md` | **Forward-looking** - current state + roadmap (Free + Pro). Trim shipped rows each release; do not add a "completed" section. |
| `docs/architecture/ARCHITECTURE.md` | Free architecture |
| `docs/architecture/FUNCTIONALITY-CATALOG.md` | Engineering view of every functional area (the tester-facing catalog is `qa/inventory/FUNCTIONALITY-CATALOG.md`) |
| `docs/architecture/architecture-contract.md` | Free ↔ Pro contract |
| `docs/architecture/core-contract.md`, `docs/architecture/buddynext-document-contract.md` | What a host plugin (BuddyNext) may rely on |
| `docs/architecture/pro/` | Pro architecture, BuddyNext drive bridge, Interactivity-API design |
| `docs/architecture/specs/` | Per-feature design specs (date-stamped history, not kept current) |
| `docs/development/` | `ls docs/development/` (coding standards, contributing, extension guide, integration event hooks, local testing for each plugin, git workflow, storage-driver verification, and others) |
| `docs/security/SECURITY_CHECKLIST.md` | Security checklist |
| `docs/standards/` | `ls docs/standards/`: synced standards (QA catalog, frontend interactivity, i18n, integration, preset licence activation) and verification guides |
| `docs/standards/authorization-guards.md` | What the four `audit/*-authority.json` manifests are (route, capability, render surface, admin action) and why each is an assertion rather than documentation. Their tests run inside the unit suite; a route that loses its permission check fails the build. |
| `docs/verification/` | Ad-hoc verification reports (e.g. `cloud-storage-verification.md`) |
| `docs/website/` | Public docs source. **Lives in this repo only** - do not publish or sync it anywhere. |
| `docs/marketing/` | Marketing asset folder (different from `marketing/`) |

---

## QA

All QA lives in `qa/` - single home for Free + Pro. Pro has no `qa/` directory of its own.

| Path | Contents |
|------|----------|
| `qa/README.md` | Index - what's where and when to run |
| `qa/runbooks/AGENT_SMOKE_RUNBOOK.md` | Pre-release gate (release-blocking) |
| `qa/runbooks/MANUAL-UX-QA-free.md` | Free manual UX walkthrough |
| `qa/runbooks/MANUAL-UX-QA-pro.md` | Pro manual UX walkthrough |
| `qa/rules/` | Organization rules - CSS, NAMING, PHP, PROCESS, RENDER-STATE |
| `qa/inventory/FUNCTIONALITY-CATALOG.md` | Every Free + Pro feature for testers and outside QA tools: who, where, steps, expected, UX expectation; plus the presentation bar and code-organization audit |
| `qa/inventory/WHAT-TO-CHECK.md` | Flat list - surfaces, actions, settings, data stores, contracts |
| `qa/audits/` | Dated audits (a11y, doc-drift, etc.) |
| `qa/runs/` | Append-only final run reports + `FINDINGS-HISTORY.md`. Drafts, raw debug logs and screenshots go to `app/qa-artifacts/`, never the repo |
| `qa/.last-smoke-pass.json` | Release-gate green-light signal (combo mode) |
| `qa/.last-smoke-pass-free.json` | Release-gate green-light signal (free mode) |

At release: can the plugin demonstrate the things in `qa/inventory/WHAT-TO-CHECK.md`? Yes → ship. No → fix what's broken. The release gate (`bin/build-release.sh`) reads `qa/.last-smoke-pass*.json` and refuses to package without a fresh green pass.

---

## Release history

Not kept here. CLAUDE.md describes what the plugin **is**; a second changelog drifts.

- Customer-facing: `readme.txt` (WooCommerce action-prefix style).
- Engineering detail: `git log --oneline`.
- Release evidence: `qa/.last-smoke-pass.json` and `audit/runs/`.

---

## Local CI pipeline (REQUIRED before push)

This plugin has a self-contained local-CI gate. No external service runs it. Every contributor runs it locally, and the pre-push git hook runs it before every `git push`.

```bash
composer install-hooks    # one-time per clone - activates bin/git-hooks/pre-push
composer ci               # full pipeline (plus browser journeys)
composer ci:no-journeys   # everything except browser-dependent journeys
composer ci:quick         # PHP lint + coding-rules only, for tight loops
composer arch-checks      # Free/Pro contract invariants (Pro plugin only)
composer journeys:dry-run # list configured journeys without executing
```

What the gate runs (in order, see `bin/local-ci.sh`):

| Stage | Tool | Catches |
|---|---|---|
| 1.1 PHP lint | `php -l` on every changed source | syntax errors |
| 1.2 WPCS | `composer phpcs` | WordPress coding standards (errors only; warnings via `composer phpcs:full`) |
| 1.3 PHPStan | `composer phpstan` | static type errors; `phpstan-baseline.neon` pins existing items |
| 1.4 CSS token-contract | `bin/css-token-contract.sh` | phantom `var(--mvs-*)` tokens + dead dark-mode selectors |
| 1.5 Duplication gate | `bin/duplication-gate.sh` | new copy-pasted method bodies vs the frozen baseline |
| 1.6 Journey coverage | `bin/journey-coverage.sh` | release-critical features missing an executable journey |
| 1.6b Erasure coverage | `php bin/check-erasure.php` | a user-keyed table that is neither ERASE nor RETAIN in the privacy map |
| 1.7 Template-style | `bin/template-style-check.sh` | inline cosmetic CSS / hardcoded hex in markup (Coding Rule 19) |
| 1.8 Dead-template check | `bin/dead-template-check.sh` | orphan templates nothing loads |
| 1.9 Template versioning | `bin/template-version-check.sh` | an overridable template without `@version`, or changed since the last tag without a bump (Coding Rule 24) |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | Rules 1-11. 1 native cap checks, 2 REST `__return_true` allowlist, 3 admin aggregates via `AdminAggregatesService`, 4 no per-entity transients, 5 REST `per_page` declares a `maximum`, 6 no refusal-as-success (Coding Rule 20), 7 no direct `mvs_media_index` query outside `MediaRepository`, 8 no exec-family call in shipped source (Coding Rule 21), 9 no ticket/card id in comments (Coding Rule 26), 10 no new method over 150 lines and baselined long methods may not grow, 11 no new `$wpdb` query outside `includes/Repository/` and the migrator. Existing debt for 9-11 is listed in `audit/cleanup/code-standard-baseline.txt` and passes until touched. Rule 7 is a hard `violation()`, mutation-tested; its allowlist is the repository layer (`Repository/MediaRepository.php`, `Repository/MediaIntegrityRepository.php`) plus `Core/Migrator.php` and `Services/AdminAggregatesService.php`, each with a written architectural reason. |
| 2.2 Architecture | `../wpmediaverse-pro/bin/architecture-checks.sh` (falls back to a local `bin/` copy; skipped if neither is checked out) | Free/Pro contract invariants; runs from the Free side too |
| 2.3 Settings contract | `composer test:contract` | register_setting whitelist alignment (skipped with a warning if `/tmp/wordpress-tests-lib` is absent) |
| 2.4 Full unit suite | `composer test:unit` | Every PHPUnit test in the plugin. A rare order-dependent flake in `CptIdCollisionTest.php` ("Linkage C") is unexplained; do not apply a speculative fix, reproduce it first. |
| 2.4 wppqa baseline | freshness check on `audit/runs/*wppqa-baseline*.md` | a missing or >14-day-old bug-finder baseline (blocks the push). The "2.4" tag is shared with the unit suite in `local-ci.sh`: two different stages, same label. |
| 2.5 UX audit | `bin/ux-audit.sh` → `audit/ux-audit-<date>.md` | ux-foundation drift; block-severity findings fail the gate, advisory ones only print |
| 3.1 Manifest | `jq` on `audit/manifests/manifest.json` | manifest validity + freshness (warns past 30 days) |
| 3.2 Functional cert | `wp mvs cert` | boot-smoke every REST route + dead-toggle oracles + toggle coverage; skipped unless `MVS_WP_PATH` points at a live WP |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end (skipped in `:no-journeys` mode) |

**Composer scripts:**
- `composer phpcs` - WPCS errors only (`--warning-severity=0`).
- `composer phpcs:full` - WPCS with warnings shown (informational).
- `composer phpcs:fix` - phpcbf to auto-fix fixable violations.
- `composer phpstan` - PHPStan with the existing baseline.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

`audit/journeys/REQUIRED-COVERS.txt` names the features that must never ship without a journey (gate 1.6 fails the build otherwise; gate 4.1 runs them). Count them with `ls audit/journeys/*/*.md audit/pro/journeys/*/*.md | wc -l`.

Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

**And prove it fails.** A journey added alongside a fix must be run against the PRE-fix code and required to FAIL. A journey that never fetched the private URL it claimed to protect, or that policed a 40px floor against a 44px token, passes while the bug is live. Skipping the revert-check is how that happens.

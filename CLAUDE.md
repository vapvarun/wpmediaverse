# WPMediaVerse — AI Quick Reference

> **TRUST ORDER:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) (code-verified state) → [`CAPABILITIES.md`](CAPABILITIES.md) (what it does, in buyer language) → the code itself (wins on any disagreement). Dated files under `qa/runs/`, `audit/runs/` and `plan/` are history — verify before trusting.
>
> **Manifest refresh: agent-enumeration-only.** `write-manifest.mjs` cannot see this plugin's registration patterns (SettingsRegistrar, ServiceContainer::register(), MediaCapabilities, PostTypes\Album::register(), CLI subcommands as methods) and would zero out settings/services/wp_cli/capabilities/post_types/taxonomies. Refresh by targeted delta against ground-truth grep — never by committing generator output. See `generated.refresh_2026_08_05` in the manifest.
>
> **READ FIRST:** [`audit/manifests/manifest.summary.json`](audit/manifests/manifest.summary.json) is a ≤2 KB index — load it first. The full inventory is in [`audit/manifests/manifest.json`](audit/manifests/manifest.json) (v2.2 schema), with detail files [`manifest.rest.json`](audit/manifests/manifest.rest.json), [`manifest.hooks.json`](audit/manifests/manifest.hooks.json), [`manifest.tables.json`](audit/manifests/manifest.tables.json). Pro audit mirror: [`audit/pro/`](audit/pro/) (manifests + journeys only). Bug-finder baseline: newest `audit/runs/*wppqa-baseline-SUMMARY.md` (local-CI stage 2.4 fails if it is >14 days old). Refresh: `/wp-plugin-onboard --refresh`.
>
> **Counts live in the manifest, not in this file.** Every bare number here has rotted at least once. Ask the code:
>
> | What | Command | 2026-09-01 |
> |---|---|---|
> | REST endpoints | `jq '.rest.endpoints \| length' audit/manifests/manifest.rest.json` | 122 |
> | REST controllers | `ls includes/REST/Controller/*.php \| wc -l` | 25 |
> | Hooks fired | `jq '.hooks_fired \| length' audit/manifests/manifest.hooks.json` | 262 |
> | Plugin AJAX actions | `grep -rhoE "add_action\( *'wp_ajax_mvs_[a-z_]+'" includes/ \| sort -u \| wc -l` | 2 (`mvs_import_demo_data`, `mvs_cleanup_demo_data`) - the looser `grep -rho "wp_ajax_mvs_[a-z_]*"` returns 3, counting a bare prefix inside a comment |
> | Admin page registrations | `grep -rn 'add_menu_page(\|add_submenu_page(' includes/ \| grep -vc 'function \|\*'` | 12 call sites (the manifest's `admin_pages: 22` counts rendered surfaces: these 12 plus 2 CPT menu entries and 8 settings tabs) |
> | Settings | `grep -rhA2 'register_setting(' includes/Admin/Settings/ \| grep -o "'mvs_[a-z_0-9]*'" \| sort -u \| wc -l` | 39 distinct options (51 `register_setting()` calls) |
> | Custom tables | `grep -c 'CREATE TABLE' includes/Core/Migrator.php` | 23 distinct names (24 statements) |
> | Registered blocks | `BlockRegistrar::BLOCKS` / `ls src/blocks/*/block.json \| wc -l` | 9 registered, 13 `block.json` (4 Interactivity-only) |
> | Container services | `grep -A1 'container->register(' includes/Core/Plugin.php \| grep -o "'[a-z_.]*'," \| sort -u \| wc -l` | 53 |
> | WP-CLI subcommands | `grep -c 'public function ' includes/CLI/Commands.php` | 20 |
> | Migrator version | `grep CURRENT_VERSION includes/Core/Migrator.php` | 30 |
>
> 2.4.0 added 4 hooks (the manifest gained 20 more on 2026-09-01 that shipped undocumented): `mvs_media_trashed` / `mvs_media_restored` (actions) and `mvs_has_custom_avatar` / `mvs_media_drive_access` (filters) — all four verified present.
>
> **Reconciled 2026-09-01.** That delta was applied in the same pass: `manifest.summary.json`
> now carries `admin_pages: 22`, `settings: 52`, `services: 54`, `wp_cli: 22`, `rest_endpoints: 122`,
> matching the commands above. Where a number here and a number there still differ, the
> summary says which unit it counted - read its `counts_note` before assuming drift.

## Quick Facts

| Key | Value |
|-----|-------|
| Version | 2.4.1 |
| PHP | >= 7.4 (header), target 8.1+ |
| WordPress | >= 6.5 |
| Namespace | `WPMediaVerse\` |
| Autoload | Hand-written PSR-4 in `wpmediaverse.php` (`WPMediaVerse\` → `includes/`). **Runtime never loads Composer.** |
| Runtime deps | Committed under `libs/` (Action Scheduler, EDD SL SDK) — see `libs/README.md` |
| `vendor/` | Dev and build tooling ONLY. Gitignored, not in the release zip, never loaded at runtime. |
| Text Domain | `wpmediaverse` |
| Custom Tables | 23 (prefixed `mvs_`) |
| REST Controllers | 25 files in `includes/REST/Controller/` (24 extend `WP_REST_Controller`; `AccountController` is a plain class), plus `Messaging\MessagingController`. Namespace `mvs/v1`. |
| Pro Extension Hook | `mvs_loaded` (fires with `ServiceContainer`) |
| Build | `npx grunt dist` |
| Entry Point | `wpmediaverse.php` -> `Plugin::init()` |
| Admin Slug | `wpmediaverse` |

---

## Module Map

Every namespace under `includes/` is listed. Class lists are complete as of 2026-09-01 —
`find includes -name '*.php' | sort` is the check.

| Namespace | Responsibility | Key Classes |
|-----------|---------------|-------------|
| `Core\` | Bootstrap, DI container, migrations, templates, settings helper, read-side URL facade, type vocabularies | `Plugin`, `ServiceContainer`, `Migrator`, `Activator`, `Deactivator`, `TemplateLoader`, `TemplateHelpers` (+`TemplateHelpersInterface`), `Abilities`, `SettingsHelper`, `MediaUrl`, `MediaTypes`, `DocumentTypes`, `DashboardSections`, `Dates` (+ `Loader`, **deprecated 2.4.0**, never used, removal 4.0.0) |
| `Admin\` | WP admin pages, moderation queue, member moderation | `OverviewPage`, `StatsPage`, `ModerationQueue`, `MemberModeration`, `ReportsPage`, `LogViewerPage`, `SetupWizard`, `CollectionMetaBox`, `MediaListPage`, `DocumentListPage`, `IntegrationsPage`, `TagManagementPage` |
| `Admin\Settings\` | Settings page (6 focused classes) | `SettingsPage`, `SettingsRegistrar`, `AiSettingsRegistrar`, `FieldRenderer`, `PermissionsManager`, `Sanitizers` |
| `REST\Controller\` | REST API endpoints | `MediaController`, `AlbumController`, `CollectionController`, `BulkController`, `ReactionController`, `CommentController`, `FavoriteController`, `StatsController`, `TagController`, `ModerationController`, `AccessController`, `SignedUrlController`, `FollowController`, `NotificationController`, `UserController`, `ReportController`, `ActivityController`, `ProfileController`, `AdminController`, `AuthController`, `ConfigController`, `InterestsController`, `TransactionController`, `AccountController`, `DeviceController` |
| `REST\` | Middleware, gates, pagination | `RateLimiter`, `RestGate`, `RestGuards`, `CommunityPrivacyGate`, `Pagination` |
| `Services\` | Business logic, storage, AI, caching, URL signing, variant pipeline, poster generation | `UploadService`, `StorageService`, `StorageRouter`, `MediaVariantWriter`, `VariantSpec`, `PosterService`, `FilenameStrategy`, `ImageOptimizationService`, `PrivacyService`, `AlbumService`, `CollectionService`, `AIService`, `OpenAIProvider`, `ModerationService`, `StatsService`, `AdminAggregatesService`, `AccessRulesService`, `SignedUrlService`, `WatermarkService`, `CacheService`, `LoggerService`, `TelemetryService`, `GDPRService`, `HealthCheckService`, `ProfileService`, `TransactionService`, `LocalDriver`, `CloudOps`, `StorageCleanupService`, `StorageRepairService`, `CptIdCollisionService`, `AccountDeletionService`, `UserDeletionService`, `ViewRetentionService` (+ `StorageDriverInterface`, `AIProviderInterface`) |
| `Social\` | Social interactions (reactions, comments, follows, push, suggestions) | `ReactionService`, `CommentService`, `FavoriteService`, `MentionService`, `ShareService`, `FollowService`, `NotificationService`, `PushService`, `ReportService`, `ActivityService`, `SuggestionService` |
| `Integrations\` | Third-party platform bridges | `WebhookService` |
| `Integrations\BuddyPress\` | BuddyPress integration (11 classes) | `BuddyPressManager`, `BaseBPTabIntegration`, `ActivitySyncIntegration`, `ActivityContentIntegration`, `ActivityFormIntegration`, `ActivityMediaLinkage`, `ActivityPrivacyFilter`, `ProfileTabIntegration`, `GroupTabIntegration`, `NotificationIntegration`, `MediaDisplayHelper` |
| `Integrations\BPVerifiedMember\` | Verified-member badge bridge | `BadgeIntegration` |
| `Integrations\Companions\` | Companion-plugin registry + installer | `CompanionRegistry`, `CompanionInstaller` |
| `Auth\` | App-password / OAuth-style app connect | `AppConnect`, `AppAuthorizeAccess`, `AppCredentials` |
| `Privacy\` | GDPR export/erase maps and purger | `MemberDataMap`, `MemberPurger` |
| `Media\` | Object↔media linkage | `ObjectMediaLinkage` |
| `Cert\` | Behavioural cert runner (`wp mvs cert`) | `CertCommand`, `CertRunner` |
| `PostTypes\` | Custom post type registration | `Album`, `Collection` |
| `Taxonomies\` | Custom taxonomy registration | `MediaTag`, `MediaCategory` |
| `Blocks\` | Gutenberg block registration | `BlockRegistrar`, `StandardAttributes`, `MVS_CSS` |
| `Shortcodes\` | Legacy shortcode support | `Shortcodes` |
| `CLI\` | WP-CLI commands | `Commands` |
| `Messaging\` | Direct messaging engine + REST routes | `MessagingService`, `MessagingController`, `NotificationListener`, `RestPollingTransport`, `TransportInterface` |
| `Repository\` | Central data access layer | `MediaRepository` (+`MediaRepositoryInterface`), `MediaIntegrityRepository` |
| `Capabilities\` | Role/capability management | `MediaCapabilities` |

**Gone, do not reference:** `Services\StoryService` and `src/blocks/story-viewer` were removed in 1.8.1 —
Stories now live in Pro (`WPMediaVersePro\Stories\StoryService`).

---

## Service Container Keys

Registered in `includes/Core/Plugin.php` via `register_services()` and `init_messaging()`.

**The key is the lookup, not a line number.** This table used to carry a `Line` column; every
number in it had rotted by 2.4.0 (`register_services()` moved from ~line 224 to ~line 475).
To find a registration, grep the key: `grep -n "'storage_router'" includes/Core/Plugin.php`.
To re-enumerate the whole table:
`grep -A1 "container->register(" includes/Core/Plugin.php | grep -o "'[a-z_.]*',"`

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
| `admin.reports` | `ReportsPage` |
| `admin.member_moderation` | `MemberModeration` |
| `admin.stats` | `StatsPage` |
| `admin.logs` | `LogViewerPage` |
| `admin.setup_wizard` | `SetupWizard` |
| `admin.collection_metabox` | `CollectionMetaBox` |
| `admin.integrations` | `IntegrationsPage` |
| `admin_aggregates` | `AdminAggregatesService` |
| `account_deletion` | `AccountDeletionService` |
| `user_deletion` | `UserDeletionService` |
| `access_rules` | `AccessRulesService` |
| `signed_urls` | `SignedUrlService` |
| `watermark` | `WatermarkService` |
| `integration.buddypress` | `BuddyPressManager` |
| `integration.bp_verified_member` | `BadgeIntegration` |
| `integration.bp_activity_linkage` | `ActivityMediaLinkage` |
| `integration.webhooks` | `WebhookService` |
| `cache` | `CacheService` |
| `image_optimization` | `ImageOptimizationService` |
| `telemetry` | `TelemetryService` |
| `follows` | `FollowService` |
| `notifications` | `NotificationService` |
| `push` | `PushService` |
| `reports` | `ReportService` |
| `activity` | `ActivityService` |
| `profile` | `ProfileService` |
| `storage_cleanup` | `StorageCleanupService` |
| `storage_repair` | `StorageRepairService` |
| `media_repository` | `MediaRepository` |
| `object_media` | `ObjectMediaLinkage` |
| `template_helpers` | `TemplateHelpers` |
| `messaging` | `MessagingService` (registered in `init_messaging()`) |

**53 keys as of 2026-09-01** — re-run the grep above rather than trusting that number.
The `stories` key is **gone** (removed with `StoryService` in 1.8.1). Plus a non-container static
helper: `Core\MediaUrl` (single read-side URL facade for non-REST callers; replaces the never-built
`Services\MediaUrl` referenced before 1.5.0). `VariantSpec` is a value object (not
container-registered) consumed by the upload pipeline.

---

## Custom Tables (23)

All prefixed with `{$wpdb->prefix}mvs_`. Defined in `includes/Core/Migrator.php`
(`Migrator::CURRENT_VERSION` is 30). Re-enumerate with
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
| `mvs_access_rules` | Per-media access control rules |
| `mvs_access_grants` | Granted access tokens |
| `mvs_album_items` | Album-to-media mapping |
| `mvs_error_log` | Internal error/debug log |
| `mvs_conversations` | DM conversation threads |
| `mvs_conversation_participants` | Conversation membership |
| `mvs_messages` | Individual DM messages |
| `mvs_message_reactions` | Reactions on DM messages |
| `mvs_transactions` | Credit/monetization transactions |
| `mvs_bp_activity_media` | BuddyPress activity-to-media mapping |
| `mvs_device_tokens` | Registered push device tokens (`/me/devices`, Migrator v30) |

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

21. **No exec-family call in shipped source.** `exec`, `shell_exec`, `proc_open`, `system`,
    `passthru`, `popen` — none of them, in either plugin. Security plugins flag them as a possible
    backdoor, and they match the CALL SITE IN THE SHIPPED FILE, not the runtime path: wrapping one
    in `if ( ffmpeg_available() )` changes nothing, and we cannot know which scanner a customer
    runs. The FFmpeg transcoding path was removed in 2.4.0 for exactly this (`c96415ba`) after it
    "kept making them panic". If a feature genuinely needs a binary, it runs somewhere else — a
    remote service, the browser, or the site owner's own mu-plugin; the three shapes are in
    `docs/architecture/specs/2026-08-30-bunny-stream-video-encoding.md` §0. Enforced by
    `bin/coding-rules-check.sh` Rule 8 in BOTH plugins, mutation-tested. (2026-08-30.)

22. **Apply a standard by RULE, never by enumerating the things it applies to.**
    When a decision holds for a whole class — "elements hidden by the Interactivity
    API stay hidden", "interactive controls clear the touch floor", "a section with
    no panel here must navigate" — express it as one rule keyed on what makes it
    true. A hand-maintained list of selectors, slugs or class names is not a
    standard; it is a standard plus a memory test, and the memory always loses.

    This is the most expensive recurring defect class in this plugin. Four separate
    2.4.1 bugs were ONE mistake wearing four hats:

    - `frontend.css` kept a twelve-selector allowlist of `[hidden]` guards, under a
      comment describing the exact bug. `.mvs-bulk-bar` and `.mvs-dashboard-loading`
      were not on it, so a bulk **Delete** bar and a permanent "Loading…" rendered on
      every dashboard on any theme without its own `[hidden]` reset. Replaced with
      `[class*="mvs-"][hidden]`, which then also fixed twelve elements nobody had
      reported.
    - `--mvs-touch-min: 44px` was applied by listing selectors, so the chat header
      buttons (32px), the chat tabs (37.5px), `.mvs-bulk-check` and
      `.mvs-load-more-btn` sat under the plugin's own floor.
    - `switchTab` said `if ( 'documents' === tab )`. Documents was the only off-site
      section anyone had hit, so it got a name-check instead of a rule; when Pro's
      Compete hub arrived it was intercepted and the content area went blank
      (Basecamp 10264172058).
    - The BP lightbox clone strips every `data-wp-*`, so each cloned button needs a
      delegated handler on a stable class. The favourite button got one
      (#10077932144). Fullscreen did not, and stayed dead for months
      (Basecamp 10264236711).

    **The tell:** if adding a feature requires remembering to add it to a list
    somewhere else, the list is the bug. Ask the thing that makes the rule true —
    the rendered DOM, the section declaration, the design token — rather than
    restating its members.

    **Corollary — a threshold is read, never retyped.** A journey asserting
    "controls >= 40px" against a plugin whose token is 44px licenses exactly the gap
    it was written to catch. Read `--mvs-touch-min` at runtime. Same for any
    contrast floor, page-size cap or timeout the plugin defines. (2026-09-03.)

23. **When you fix a bug, sweep the class before you close it.** CLAUDE.md has said
    "fix the class, sweep every surface" for a while; rules 22's four cases are what
    happens when that is skipped. Two of them were *identical* to a defect already
    fixed elsewhere in the same file, months apart — the favourite button and the
    fullscreen button, the `.mvs-share__section[hidden]` fix and `.mvs-bulk-bar`.

    Concretely: after fixing, grep for the shape (not the symptom) across BOTH
    plugins, and say in the commit what the sweep covered and what it did not. A fix
    with no sweep note is half a fix. (2026-09-03.)

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

> **Debt criterion (2026-05-03 update):** A file lands here only when it has a CONCRETE structural problem — duplicate sibling classes, multiple unrelated responsibilities, a 350-line method, etc. Size alone is not a reason. For a plugin at WPMediaVerse's scale (53 container services, 25 REST controllers, Free + Pro pair), files in the 1k–3k range are normal and healthy as long as they're focused on one responsibility. The team splits at ~2.5k+ when a file's scope genuinely outgrows one class (BP manager was 2,811; Settings was 2,401 — both already split).

**Line counts below are `wc -l` as of 2026-09-01.** Re-measure before quoting one; the previous
set in this table was 6–18 months stale and understated four files by more than 2×.

| File | Issue | Status |
|------|-------|--------|
| `includes/Integrations/BuddyPress/` | (was 2,811-line manager mixing 7 unrelated BP integration concerns) | DONE — split; `BuddyPressManager.php` is now 118 lines and the namespace holds 11 focused classes |
| `includes/Admin/Settings/` | (was 2,401-line registrar with 7 settings groups + UI + sanitizers in one) | DONE — split into 6 classes (except `SettingsRegistrar.php`, see below) |
| `includes/Integrations/BuddyPress/ProfileTabIntegration.php` ↔ `GroupTabIntegration.php` | (was 80% duplicate method bodies between the two siblings) | DONE 2026-05-03 (Phase 5 P2.4) — `BaseBPTabIntegration` extracted; subclasses now hold only context-specific overrides. |
| `includes/Admin/Settings/SettingsRegistrar.php` | Consolidates the remaining settings groups (general+storage, display, moderation, webhooks, messaging, pages). The AI group was extracted to `AiSettingsRegistrar` in 1.4.0 (1,168 → 914) — follow that pattern for the others when next touched. | **OPEN, and GROWING — 1,190 lines** (2026-09-01), i.e. above the pre-extraction size. The debt tax (Rule 15) has not been honoured on this file. Next edit must extract a group, not add one. |
| `includes/Services/UploadService.php` | (was 1,482 lines mixing 4 concerns: validation, type detection, storage routing, progress tracking) | **OPEN, and GROWING — 2,217 lines** (2026-09-01). 1.5.0 did extract `MediaVariantWriter` / `StorageRouter` / `PosterService` (1,482 → 1,211), but the file has since grown past where it started. `ValidatorService` and `ProgressTrackerService` were never created — they do not exist in `includes/Services/`. |
| `includes/REST/Controller/MediaController.php::replace_file` | ~260-line method that orchestrates its own ingest instead of calling `UploadService::handle()`. Every step that can drift has already been pulled into a shared seam — `FilenameStrategy::pick()`, `apply_exif_orientation()`, `watermark->stamp_new_upload()`, `process_stored_file()` — so the two paths no longer duplicate *logic*; what remains is the orchestration shell, and it genuinely differs (replace UPDATES an existing row and must not reset stats or mint a new media_id, `handle()` CREATES one). | **STILL OPEN.** The 2026-08-06 decision (Basecamp 10156642711) deferred this to 2.4.0; 2.4.0 is the current version and the method was not collapsed. Re-decide rather than re-defer: collapsing the shell means teaching `handle()` an update mode, a behaviour change on the upload path that Production Rule 7 forbids in a patch. Debt tax applies now: no new inline ingest logic in this method — extract a seam and call it from both sides. |

**Files that are large but NOT debt** (mentioned because someone might wonder), `wc -l` 2026-09-01:
`MediaRepository.php` (5,380), `Plugin.php` (3,065), `MessagingService.php` (3,030),
`MediaController.php` (2,251), `MessagingController.php` (1,032). They are large because their
domain is genuinely large, not because responsibilities are tangled. Don't propose splits unless a
real organizational problem surfaces — but note `MediaRepository.php` has grown 6× since this row
was written, and is worth a look for a genuine seam next time someone is in it.

**Debt tax (Coding Rule #15):** No PR adds lines to a file in the OPEN rows above. Every edit must reduce the line count or extract code out. Files in the "large but NOT debt" list have no debt tax — edit them normally.

---

## Testing

| Key | Value |
|-----|-------|
| Framework | PHPUnit 9.6 + yoast/phpunit-polyfills 2.x |
| Test dir | `tests/unit/` |
| Test files | 53 as of 2026-09-01. This line has been wrong twice (it said 11, then 42) because a hardcoded count rots the moment anyone adds a file — run `ls tests/unit/*.php \| wc -l` rather than trusting it. |
| Suite size | 450 tests / 1,027 assertions / 1 skipped, green on 2026-09-01. Same caveat — `composer test:unit` is the number. |
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
| `audit/` | Machine-derived audits — `manifests/`, `journeys/`, `runs/`, `cleanup/`, `conformance/`, `ux/`, dated `ux-audit-*.md`, `cert-*.json`. Pro mirror `audit/pro/` currently holds **manifests + journeys only**. `audit/reports/` is empty and `audit/graph.html` does not exist — the FEATURE_AUDIT / CODE_FLOWS / ROLE_MATRIX reports were never regenerated after the 2026-07-07 refresh. |
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
| `docs/website/` | Public docs source. **Lives in this repo only** — do not publish or sync it anywhere. |
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

## Release history

Not kept here, deliberately. CLAUDE.md describes what the plugin **is right
now**; a changelog describes what it **was**, and a second copy drifts from the
first while burying the current-state material this file is opened for.

- Customer-facing: `readme.txt` (WooCommerce action-prefix style).
- Engineering detail: `git log --oneline`, where these entries came from.
- Release evidence: `qa/.last-smoke-pass.json` and `audit/runs/`.

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

| Stage | Tool | Catches | Status as of 2026-09-01 |
|---|---|---|---|
| 1.1 PHP lint | `php -l` on every changed source | syntax errors | ✅ exits 0 |
| 1.2 WPCS | `composer phpcs` | WordPress coding standards (errors only; warnings via `composer phpcs:full`) | ✅ exits 0 — 0 errors. Warning count is not pinned here (it was quoted as 41, actual 34 on 2026-09-01); run `composer phpcs:full` for the number. |
| 1.3 PHPStan | `composer phpstan` | static type errors | ✅ exits 0 — `phpstan-baseline.neon` pins existing items |
| 1.4 CSS token-contract | `bin/css-token-contract.sh` | phantom `var(--mvs-*)` tokens + dead dark-mode selectors | ✅ exits 0 |
| 1.5 Duplication gate | `bin/duplication-gate.sh` | new copy-pasted method bodies vs the frozen baseline | ✅ exits 0 |
| 1.6 Journey coverage | `bin/journey-coverage.sh` | release-critical features missing an executable journey | ✅ exits 0 |
| 1.6b Erasure coverage | `php bin/check-erasure.php` | a user-keyed table that is neither ERASE nor RETAIN in the privacy map | ✅ exits 0 |
| 1.7 Template-style | `bin/template-style-check.sh` | inline cosmetic CSS / hardcoded hex in markup (Coding Rule 19) | ✅ exits 0 |
| 1.8 Dead-template check | `bin/dead-template-check.sh` | orphan templates nothing loads | ✅ exits 0 |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific Rules 1–8 (1 native cap checks, 2 REST `__return_true` allowlist, 3 admin aggregates via `AdminAggregatesService`, 4 no per-entity transients, 5 REST `per_page` declares a `maximum`, 6 no refusal-as-success, 7 no direct `mvs_media_index` query outside `MediaRepository`, 8 no exec-family call in shipped source) | ✅ all 8 pass. Rule 7 (added 2026-08-11, **hard `violation()` since 2026-08-15**): all 32 tracked call sites migrated across `CLI/Commands.php` (11), `Services/CloudOps.php` (8), `Services/CptIdCollisionService.php` (6), `REST/Controller/MediaController.php` (4) and `Services/StorageRepairService.php` (3). Mutation-tested — a planted leak fails the script with exit 1. Allowlist is the repository layer (`Repository/MediaRepository.php`, `Repository/MediaIntegrityRepository.php`) plus `Core/Migrator.php` and `Services/AdminAggregatesService.php`, each with a written architectural reason. Rule 8 (2026-08-30) enforces Coding Rule 21 — 0 hits. |
| 2.2 Architecture | `../wpmediaverse-pro/bin/architecture-checks.sh` (falls back to a local `bin/` copy; skipped if neither is checked out) | Free/Pro contract invariants | ✅ runs from the Free side too — this row previously said "(Pro only)", which is wrong |
| 2.3 Settings contract | `composer test:contract` | register_setting whitelist alignment (catches the d986525 bug class) | ✅ exits 0 (skipped with a warning if `/tmp/wordpress-tests-lib` is absent) |
| 2.4 Full unit suite | `composer test:unit` | Every PHPUnit test in the plugin | ✅ green 2026-09-01 — 450 tests / 1,027 assertions / 1 skipped. Added 2026-08-11, mirroring the gap closed on Pro the same day (Basecamp #10184313297). **Known rare flake, still unexplained:** `CptIdCollisionTest.php` showed 2 order-dependent failures ("Linkage C") on 1 of 8 full-suite runs measured 2026-08-11. Confirmed NOT Pro's root cause (`MediaRepository::reset_test_cache()`) — no Free test file truncates `mvs_media_index`. Targeted repros did not reproduce it. **No speculative fix applied** — a guessed fix with a ~1-in-8 signal to validate against would be indistinguishable from dead code. See `plan/2026-08-11-pro-competitions-test-triage-plan.md`'s closing note. |
| 2.4 wppqa baseline | freshness check on `audit/runs/*wppqa-baseline*.md` | a missing or >14-day-old bug-finder baseline (blocks the push) | ✅ latest is `2026-08-28-wppqa-baseline-SUMMARY.md`. Tag "2.4" is shared with the unit suite in `local-ci.sh` — two different stages, same label. |
| 2.5 UX audit | `bin/ux-audit.sh` → `audit/ux-audit-<date>.md` | ux-foundation drift; block-severity findings fail the gate, advisory ones only print | ✅ exits 0 |
| 3.1 Manifest | `jq` on `audit/manifests/manifest.json` | manifest validity + freshness (warns past 30 days) | ✅ exits 0 |
| 3.2 Functional cert | `wp mvs cert` | boot-smoke every REST route + dead-toggle oracles + toggle coverage | skipped unless `MVS_WP_PATH` points at a live WP |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end | (skipped in `:no-journeys` mode) |

**Composer scripts (added 2026-05-01):**
- `composer phpcs` — runs WPCS errors only (`--warning-severity=0`); exits 0 on baseline.
- `composer phpcs:full` — runs WPCS with warnings shown (informational; still exits 0 if no errors).
- `composer phpcs:fix` — runs phpcbf to auto-fix fixable violations.
- `composer phpstan` — runs PHPStan with the existing baseline; exits 0.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

38 Free + 13 Pro as of 2026-09-03, and `audit/journeys/REQUIRED-COVERS.txt` names the 30 features that must never ship without one (gate 1.6 fails the build otherwise; gate 4.1 runs them). Counts rot — `ls audit/journeys/*/*.md audit/pro/journeys/*/*.md | wc -l`.


Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

**And prove it fails.** A journey added alongside a fix must be run against the PRE-fix code and required to FAIL. Two `priority: critical` journeys passed for months while the bugs they named were live: `security/05` captured a private file's URL and never fetched it, and `customer/08` policed a 40px touch floor against a 44px token. Both claimed coverage they did not assert. Skipping the revert-check is how that happens.

# Doc Drift Audit — 2026-05-09

**Scope:** WPMediaVerse (Free) + WPMediaVerse Pro, both at v1.2.1.
**Audit method:** four parallel Sonnet agents, one per stream. Each ran a fresh read of the relevant files and cross-referenced against current code at HEAD. Findings reviewed but not yet acted on (Audit + Report Only mode).

**Streams audited:**
1. QA runbook coverage vs feature manifests (Free + Pro)
2. WP.org `readme.txt` freshness (Free + Pro)
3. Internal dev docs (`docs/*.md` in both plugins)
4. Public docs site (`docs/website/`), in-repo marketing (`marketing/`), live EDD store pages (`store.wbcomdesigns.com/wpmediaverse[-pro]/`)

---

## Executive summary — what blocks or embarrasses on release

These are the items that MUST be fixed before any paying customer touches 1.2.1 (in priority order):

### Customer-facing CRITICAL (will mis-sell the product)

1. **Live store page headers say wrong product + wrong version.** `store.wbcomdesigns.com/wpmediaverse/` still reads "BuddyPress v1.0.0"; Pro page reads "Cloud Storage v1.0.0". Customers landing here see what looks like a different product altogether. → **Stream 4**
2. **DM ownership mis-attributed across 5+ surfaces.** Free's "Pro Adds" section sells voice/read-receipts/typing as Pro. Pro's `readme.txt` description has a "Direct Messaging" section. Reality: all DM lives in Free since 1.2.0 (commit `b30d7fd`). Customers buying Pro for DMs are buying a feature they already had. → **Streams 2 + 4**
3. **Pro readme says `Requires WPMediaVerse 1.0.0+` — will fatal in production.** Pro 1.2.1 calls Free 1.2.1-only APIs (`Pagination::resolve_per_page`, `Services\CloudOps`, `StorageDriverInterface::download()`). Anyone running Pro 1.2.1 on Free 1.2.0 hits fatals on first migrate-storage call. → **Stream 2**
4. **No 1.2.1 changelog landing page anywhere.** EDD update notification → customer clicks → `whats-new-1-2-0.md`. Live store changelog widget absent. They have nowhere to discover what just shipped. → **Streams 2 + 4**
5. **Both readmes missing 1.2.1 `== Upgrade Notice ==` block.** WP.org will not surface the update prompt. Customers won't see the 1.2.1 update through the WP admin updates UI. → **Stream 2**
6. **`feature-comparison-chart.md` mis-sells Free as weaker than it is.** Marks Multi-file upload, Audio support, Gutenberg blocks (12+ shipped since 1.0.0!), per-item Public/Private privacy, Friends-only, Members-only, Album-level privacy ALL as "Pro" or "Planned." Genuinely Free features sold as Pro — customers feel deceived when they realize. → **Stream 4**

### Developer-facing CRITICAL (will break third-party extensions)

7. **`EXTENSION_GUIDE.md` + `CONTRIBUTING.md` give wrong interface signatures for `StorageDriverInterface` and `AIProviderInterface`.** Third-party storage drivers following the docs will fatal at instantiation:
   - Doc says: `upload, delete, get_url, exists` (4 methods, wrong names)
   - Reality: `store, delete, url, exists, get_full_path, download` (6 methods, names different)
   - AI provider doc says: `analyze, moderate, tag` (3 methods)
   - Reality: `analyze_image, generate_tags, moderate_content, is_available, get_id` (5 methods, names different)
   → **Stream 3**
8. **`developer-guide/wp-cli.md` misses all 3 new 1.2.1 CLI commands** + sample output says `Plugin Version | 1.0.0`. Customers running `wp mvs migrate-storage` for cloud migration won't find the doc. → **Stream 4**
9. **`pro-features/cloud-storage.md` outright contradicts 1.2.1.** Says *"Switching drivers does not migrate existing files."* 1.2.1 ships exactly that migration tool. → **Stream 4**

### QA gate CRITICAL (will let bugs ship)

10. **AGENT_SMOKE_RUNBOOK doesn't cover ~80 manifest entries.** 51 REST endpoints / 14 named in runbook. 18 hooks / 7 named. 0/9 AJAX. 0/6 cron. 0/25 blocks+shortcodes named individually. 0/13 cross-plugin Pro listeners. → **Stream 1**

---

## Cross-cutting drift (affects multiple surfaces)

| Issue | Surfaces affected | Action |
|---|---|---|
| **DM ownership** disputed (Free vs Pro) | Pro readme.txt, Free readme.txt, `getting-started/free-vs-pro.md`, `landing-page.md`, `product-description.md`, `one-pager.md`, `feature-comparison-chart.md`, EDD generators | Lock to: text DM is Free; voice/receipts unclear in code, decide and propagate |
| **Version stale 1.2.0 → 1.2.1** | `audit/manifest.json` (Free + Pro), `cloud-storage-verification.md` (says "1.2.2-dev"), `docs/website/docs_config.json`, EDD store page header badge, `developer-guide/wp-cli.md` sample | One-shot: bump every version reference |
| **Layout mode count: 4 vs 5** | `landing-page.md`, `one-pager.md`, `messaging-guide.md`, EDD generators | Lock to "5 modes (Grid free, 4 Pro)" |
| **CLI command count: 8 → 13** | All marketing surfaces, EDD generators, dev docs | Bump to 13 |
| **Block count: 12 vs 13** | readme.txt + marketing say 13; CLAUDE.md says 12 | Verify canonical count and propagate |
| **Image library all pre-1.2.0** | `docs/website/images/` (45 files dated 15 Apr 2026) | Re-screenshot post-1.2.1 surfaces (Storage Management UI, filename strategy, view retention, OG/Twitter unfurl, per-media Edit modal, Bulk Actions, Lightbox Download) |
| **Class names from pre-1.2.0** | `docs/ARCHITECTURE.md` (both plugins), `CODING_STANDARDS.md`, `CONTRIBUTING.md`, `REFACTORING_ROADMAP.md`, `INTERACTIVITY-API-ARCHITECTURE.md` | Refresh against current `Integrations/<Platform>/` layout |
| **Coding rules: 12 in CODING_STANDARDS.md vs 16 in CLAUDE.md** | CODING_STANDARDS.md missing rules 11–16 (render state, CSS file ownership, names don't lie, sibling-class duplication, debt tax, cache backend by cardinality) | Sync the doc to CLAUDE.md as source-of-truth |
| **Pre-push gate documentation split** | LOCAL_TESTING.md (Free + Pro) describes manual smoke; CLAUDE.md describes `composer ci` pipeline | LOCAL_TESTING.md → reference `composer ci` and friends |

---

## Stream 1 — QA runbook coverage gap

Manifest counts vs runbook coverage. Numbers below are explicitly-named contracts. The runbook has bundled summaries ("all 12 blocks", "all 31 settings") but bundle phrasing won't catch a regression in any specific entry.

### Free

| Manifest category | Total | Covered | Top uncovered |
|---|---|---|---|
| REST endpoints | 53 | ~14 | `POST /mvs/v1/media/{id}/share`, `/download`, `/me/profile` GET/PUT/PATCH, `/me/avatar` POST/DELETE, `/users/{id}/follow`, `/users/{id}/report`, `/users/{id}/block`, `/feed`, `/users/{id}/activity`, `/me/notifications`, `/notifications/read`, `/notifications/count`, `/me/conversations`, all 8 messaging routes, `/albums/{id}/items` POST, `/collections` CRUD, `/users/search`, `/tags/cloud`, `/tags/(\d+)`, `/tags/merge`, `/me/stats`, `/media/{id}/stats`, `/media/{id}/rules`, `/grant` |
| Hooks fired | 20 | 7 | `mvs_after_thumbnail_generation`, `mvs_thumbnail_size_resolved`, `mvs_thumbnail_sizes`, `mvs_watermark_invalidated`, `mvs_should_render_chat_panel`, `mvs_page_id_*` (3), `mvs_notification_created`, `mvs_media_privacy_changed`, `mvs_loaded`, `mvs_media_response`, `mvs_moderation_tabs`, `mvs_stats_tabs` |
| AJAX actions | 3 | 0 | `mvs_import_demo_data`, `mvs_cleanup_demo_data`, `mvs_dismiss_welcome` |
| Settings | 33 | ~9 | `mvs_strip_exif`, `mvs_duplicate_action`, `mvs_grid_columns`, `mvs_items_per_page`, `mvs_thumbnail_style`, `mvs_show_online_status`, `mvs_report_auto_hide_threshold`, `mvs_moderation_auto_action`, all 6 AI settings (budget/cost-cap/circuit), `mvs_storage_driver`, `mvs_filename_strategy`, `mvs_cloud_direct_public_urls`, `mvs_page_dashboard/explore/upload`, `mvs_webhooks` |
| Tables | 21 | 9 | `mvs_media_views`, `mvs_mentions`, `mvs_activity`, `mvs_blocks`, `mvs_access_rules`, `mvs_access_grants`, `mvs_album_items`, `mvs_error_log`, `mvs_message_reactions`, `mvs_transactions` |
| Blocks | 12 | 0 named | All 12 referenced as a bundle; no per-block populated/empty fixture |
| Shortcodes | 12 | 0 named | Same |
| Admin pages | 7 | 5 | Setup Wizard (no flow walk), Logs (no render fixture), All Media (no admin bulk-trash fixture) |
| Cron events | 2 | 0 | `mvs_prune_logs`, `mvs_story_cleanup` |
| WP-CLI | 13 (1.2.1: +3) | 0 explicit | `regenerate-thumbnails`, `recount-stats`, `export-user-data`, `migrate-storage`, `cloud-thumbs-backfill`, `cleanup-local`, etc. |

### Pro

| Manifest category | Total | Covered | Top uncovered |
|---|---|---|---|
| REST endpoints | 37 | ~6 | All 7 connector routes, 4 battle action routes (accept/decline/submit/vote), 4 challenge routes (cancel/entries/vote-entry/results), tournament register/bracket/vote-match, `/me/quota` + admin assign, boost endpoints, captions, transcode start/status, video chapters/resume/analytics, privacy settings, `/compete-summary` |
| AJAX | 6 | 0 | `mvs_pro_test_s3`, `mvs_pro_test_bunny`, `mvs_save_connector_prefs`, `mvs_dismiss_gamification_welcome`, `mvs_migration_batch`, `mvs_migration_reset` |
| Tables | 8 | 4 | `mvs_quota_packages`, `mvs_credit_log`, `mvs_play_events`, `mvs_competitions`, `mvs_competition_matches`, `mvs_competition_votes` |
| Cron | 4 | 0 | `mvs_pro_transcode_cleanup`, `mvs_pro_challenges_autopilot_weekly`, `mvs_pro_streaks_daily_reset`, `mvs_pro_prune_play_events` |
| Cross-plugin Pro listeners | 13 | 6 | `mvs_dashboard_tabs`, `mvs_dashboard_panels`, `mvs_settings_sections`, `mvs_locate_template`, `mvs_feed_media_ids`, `mvs_after_content`, `mvs_before_explore_grid`, `mvs_before_upload_form`, `mvs_dashboard_after_content`, `mvs_quota_render_mapping_fields`, `mvs_quota_save_mapping`, `mvs_upload_args`, `mvs_privacy_can_view`, `mvs_notification_types`, `mvs_notification_message`, `mvs_settings_group_labels`, `mvs_settings_render_license` |
| Pro blocks | 12 | 0 named | Only Instagram covered; 4 feed blocks via D.pro-block-layout-enqueue (Rule 6); leaderboard, compete-hub, tournaments-list, challenges-list, battles-active, single battle/challenge/tournament unnamed |
| Pro shortcodes | 12 | 0 named | All Pro shortcodes (`[mvs_pro_*]`) absent from `C.shortcodes` |
| Pro admin pages | 10 | 5 | Competitions Dashboard, Analytics Dashboard, Battle Monitor, ChallengeManager, TournamentManager, Theme Library, ReportManager all reached only via "Pro admin pages" sweep |
| Pro WP-CLI | 3 | 0 explicit | `wp mvs migrate rtmedia/mediapress/buddyboss` |

**Action:** add per-entity contracts to the runbook for every "0 named" or "<50% covered" row above. Estimated: 30–40 new C/E rows + 3-5 new D rows.

### Note: Sonnet false-positive prevention

Pro uses **Action Scheduler** (`as_schedule_recurring_action`) not WP Cron (`wp_schedule_event`). Add a runbook note so future smoke walks don't re-flag this gap (already happened once in the soft run, 2026-05-09).

---

## Stream 2 — readme.txt freshness

### Free `readme.txt`

| Severity | Count | Examples |
|---|---|---|
| Critical | 1 | Missing `== Upgrade Notice ==` 1.2.1 block (WP.org won't surface update prompt) |
| Major | 7 | "8 WP-CLI Commands" → reality 13; "80+ REST API Endpoints / 17 controllers" → reality 53 / 19; "8 Shortcodes" → 12; FAQ misses 4 new shortcodes; "What You Get (Free)" omits cloud-storage tooling; changelog mentions 1 of 3 new CLI subcommands; "Pro Adds" misattributes voice/receipts (DM is Free); Free vs. Pro confusion |
| Minor | 5 | `Tested up to: 6.9` (unreleased); CloudOps service not mentioned in changelog; i18n sweep absent; 100k-readiness story not in Features; screenshot undercoverage |

### Pro `readme.txt`

| Severity | Count | Examples |
|---|---|---|
| Critical | 4 | "Requires WPMediaVerse 1.0.0+" — actual floor is 1.2.1, will fatal in production; Description has "Direct Messaging" section selling Free features as Pro; missing competitions/gamification entirely from Description (Battles, Challenges, Tournaments, Boosts, Streaks); changelog 1.2.1 missing Storage Management UI (the headline feature) |
| Major | 7 | Lists 4 admin pages, code has 10; missing 12 Pro Gutenberg blocks; missing Connectors framework; changelog missing S3 SigV4 fix, BunnyCDN HEAD→Range-GET fix, BunnyCDN/AWS UX fixes, JS i18n sweep; "Cloud Storage" section understates the new admin UI |
| Minor | 4 | `Tested up to: 6.9`; "messaging" tag misleading (DM moved to Free); FAQ doesn't reference 1.2.1 Storage Management UI; HLS streaming claim unverified |

### Cross-readme

- Manifest version stale: `audit/manifest.json` reports `"version": "1.2.0"` for both plugins; code is at 1.2.1.
- Demo URL (`https://app.instawp.io/launch?s=wpmediaverse&d=v2`) — verify it's a 1.2.1 sandbox.
- Shared support URL `store.wbcomdesigns.com/wpmediaverse[-pro]/docs/` — verify resolves before release.

---

## Stream 3 — Internal dev docs

### Free `docs/`

Top critical drift (will mislead a contributor or third-party extension developer):

- **`EXTENSION_GUIDE.md` §2:** describes `StorageDriverInterface` with 4 methods named `upload, get_url`. Reality: 6 methods, names `store, delete, url, exists, get_full_path, download`. Following this guide produces a non-conforming driver.
- **`EXTENSION_GUIDE.md` §3:** describes `AIProviderInterface` with 3 methods `analyze, moderate, tag`. Reality: 5 methods `analyze_image, generate_tags, moderate_content, is_available, get_id`.
- **`CONTRIBUTING.md` §5–§7:** points at deleted directories — `includes/Storage/`, `includes/AI/`. Pro 1.2.0 Phase 2 moved everything to `Integrations/<Platform>/`. Following this guide trips Rule 4 in `bin/coding-rules-check.sh`.
- **`CONTRIBUTING.md` §5 Step 2:** lists 5 storage-driver methods. Now 6 (added `download()` in 1.2.1). Driver missing it triggers fatal at migration time.
- **`CONTRIBUTING.md` §3 Step 4:** debt-files list still names `BuddyPressIntegration.php` (deleted), `SettingsPage.php` (deleted), and `MessagingService.php` (no longer debt per CLAUDE.md).
- **`ARCHITECTURE.md` header:** "Migrator version: 9, MVS_VERSION 1.1.0, 33 services, 18 controllers". Reality: Migrator v13, MVS_VERSION 1.2.1, 39 services (CLAUDE.md says 34 — itself stale), 19 controllers.
- **`ARCHITECTURE.md` §1:** references singleton `BuddyPressIntegration` class — split into 7 classes under `Integrations/BuddyPress/` in 1.2.0.
- **`REFACTORING_ROADMAP.md`:** 5 of 9 listed "TODO" items are SHIPPED with different class names than predicted (BP split, Settings split, MigrationPage split, AbstractBatchImporter, boundary interfaces).
- **`CODING_STANDARDS.md`:** 12 hard rules listed. CLAUDE.md defines 16. Missing rules 11–16.
- **`SECURITY_CHECKLIST.md`:** no mention of 1.2.1 cloud privacy gate, `mvs_cloudops_allow_non_public_to_cloud` filter, `mvs_filename_strategy` PII concerns, or AdminAggregatesService Rule 3.
- **`cloud-storage-verification.md`:** header reads "1.2.2-dev"; should be 1.2.1.
- **`LOCAL_TESTING.md`:** describes manual smoke; doesn't reference `composer ci` / `bin/local-ci.sh` / `bin/coding-rules-check.sh` (the canonical pre-push gate).
- **`MOBILE_UX_GUIDELINE.md`:** no reference to 1.2.1 Storage Management modal pattern (`Admin\CloudOpsManager`).

### Pro `docs/`

- **`ARCHITECTURE.md` §1 step 7:** "MemberPressAdapter, PaidMembershipsProAdapter, WooCommerceAdapter" — moved to `Integrations/<Platform>/QuotaAdapter.php` in Phase 2.
- **`ARCHITECTURE.md` §3.11 Messaging:** lists 18 messaging endpoints under `mvs-pro/v1`. Messaging is Free-owned now — same doc §2.4 acknowledges this. Internally contradictory.
- **`ARCHITECTURE.md` §4.4:** documents 13 messaging filters/actions as Pro-fired. They live in Free.
- **`ARCHITECTURE.md` (whole doc):** no mention of boundary rule, `MediaRepositoryInterface`, `TemplateHelpersInterface`, `Plugin::free_service()`, the 5 extension patterns, or Pro Coding Rule 6 (block render.php must enqueue Layout assets).
- **`INTERACTIVITY-API-ARCHITECTURE.md`:** lives in Pro `docs/` but describes Free internals (`mvs/shared-ui` is Free). Either move or reframe as "what Pro relies on from Free."
- **`LOCAL_TESTING.md`:** doesn't reference Pro's `composer ci`, `composer arch-checks`, or `bin/architecture-checks.sh`.
- **`specs/2026-04-13-platform-connector-design.md`:** design-stage spec for connectors that have shipped; archive or relabel as "as-built."

### Most dangerous (single highest-value fix)

**Free `EXTENSION_GUIDE.md` + `CONTRIBUTING.md` interface signatures.** Anyone writing a 3rd-party Pro extension following these docs will produce a class that triggers a fatal at instantiation. **Fix this first.**

---

## Stream 4 — Public docs site, marketing, EDD store

### Public docs site (`docs/website/` source for `roadmap.local/docs/wpmediaverse/`)

- `docs_config.json` says `"version": "1.2.0"`; canonical `docs_url` host inconsistent with rest of system.
- Sidebar still routes to `getting-started/whats-new-1-2-0`; no `whats-new-1-2-1` page exists.
- `getting-started/free-vs-pro.md`: "Both editions ship together at 1.2.0 (May 5, 2026)" — bypassed by 1.2.1 (May 7); comparison table missing every 1.2.1 feature; "Voice messages | Yes | Yes" puts them in Free (contradicts every other surface).
- `settings/general.md`: omits 1.2.1 settings (`mvs_cloud_direct_public_urls`, `mvs_filename_strategy`, `mvs_view_retention_days`); Default Privacy dropdown lists 3 options (reality: 6).
- `settings/display.md`: "Grid Columns: 2, 3, 4 columns" (1.1.2 added 5); "video files: placeholder thumbnail" (1.1.3 added real video poster extraction + `wp mvs generate-video-thumbnails`).
- `pro-features/cloud-storage.md`: outright contradicts 1.2.1 ("Switching drivers does not migrate existing files"); no mention of `mvs_cloud_direct_public_urls`, cloud-side thumbnails, privacy gate filter, `mvs_cloud_thumbnail_url`, S3 SigV4 fix, Storage Management admin UI, or `cloud-thumbs-backfill` / `cleanup-local` CLI.
- `developer-guide/wp-cli.md`: sample shows `Plugin Version | 1.0.0`; missing 3 new 1.2.1 commands; tells users to set up `/etc/cron.d/wpmediaverse` for prune-views, but 1.2.1 ships `mvs_purge_old_views` cron.
- `developer-guide/custom-storage-drivers.md`: must reflect new `StorageDriverInterface::download()` contract (1.2.1 BC break for third-party drivers); if missing, third-party authors hit fatals on `migrate-storage`.
- `images/`: all 45 PNG/JPG dated 15 Apr 2026 (pre-1.2.0). No Storage Management UI, filename strategy, view retention, direct CDN toggle, OG/Twitter unfurl, per-media Edit modal, Bulk Actions, Lightbox Download screenshots.
- `image_map.json` does not exist → first-time publish behavior (every image re-uploads).

### In-repo marketing (`marketing/`)

- `03-website-copy/landing-page.md`: "designed from scratch in 2025" (it's 2026); "13 Gutenberg blocks" (12 in CLAUDE.md); "8 WP-CLI commands" (now 13); "Elementor support is on roadmap" — Pro has 12 blocks shipped.
- `03-website-copy/product-description.md`: Free description repeats stale counts; "voice messages and read receipts" listed as Pro (contradicts `free-vs-pro.md`).
- `03-website-copy/feature-comparison-chart.md`: **highest-impact misrepresentation.** Marks Multi-file upload, Audio support, Gutenberg blocks (12+ shipped since 1.0.0!), per-item Public/Private privacy, Friends-only, Members-only, Album-level privacy ALL as "Pro" or "Planned." Plus "Last updated: March 2026" (2 months stale) and `~$X/yr` placeholder pricing.
- `06-sales-materials/one-pager.md`: "Four layout modes" (rest of system: 5); "Instagram-style feed" listed under Free (it's Pro); pricing all `$X/year` placeholders.
- Pro plugin has no `marketing/` directory.

### Live EDD store pages (`store.wbcomdesigns.com/wpmediaverse[-pro]/`)

- **Free page header badge: "BuddyPress v1.0.0"** (wrong product name AND wrong version). Should be "WPMediaVerse v1.2.1".
- **Pro page header badge: "Cloud Storage v1.0.0"** (wrong product name AND wrong version). Should be "WPMediaVerse Pro v1.2.1".
- Hero on both: no "What's new in 1.2.1" / launch framing.
- Both pages: changelog/recent-updates widget absent.
- Free feature grid (9 items): missing every customer-discovery item shipped 1.0→1.2.1 (filename strategy, FULLTEXT search, view-retention, cloud migration, Bulk Actions, Edit modal, OG/Twitter Cards, Member Photos block, PDF Viewer block, Lightbox Download/Fullscreen).
- Pro feature list (12 items): missing 12 Pro Gutenberg blocks, Storage Management admin UI (1.2.1), Mix layouts per-page (1.2.0), MigrationPage rebuild.
- Pro page mis-attributes "Direct messaging — voice/read receipts" as Pro (DM is Free).
- Screenshot library on both pages is launch-era.

### Generators (`~/.claude/skills/store-product-publisher/`)

- **`generate-wpmediaverse-free.py` and `generate-wpmediaverse-pro.py` are the source of the live page content.** Re-running them won't fix the stale-content problem unless the PLUGIN_DATA arrays in each `.py` are updated to 1.2.x feature copy first.
- **Generators don't override the SnipShare template's hard-coded version pill** — that's why the live pages still show "v1.0.0" headers. The `badge` field only sets the eyebrow chip text.
- Free generator features list (9 items) has zero 1.2.x material.
- Pro generator FAQ "How does cloud storage work?" answers "with the included CLI command" — should be concrete: `wp mvs migrate-storage --from=local --to=s3` + the new "Move next 20" button.

---

## Recommended fix order

Tier the work by risk × effort. Pick from the top down.

### Tier 1 — block the release (do these first)

1. **Bump Pro `Requires WPMediaVerse: 1.2.1`** — both header (`Requires Plugins: wpmediaverse 1.2.1`) and readme description. Stops production fatals.
2. **Add `== Upgrade Notice ==` 1.2.1** block to both readmes. Restores WP.org update prompt.
3. **Decide DM ownership** (text vs voice vs receipts) and propagate the decision to both readmes + 5 marketing surfaces. Stops the cross-surface contradiction.
4. **Bump live store page header version pills** — patch the generators to override the SnipShare template's version pill. Re-publish both pages.
5. **Write 1.2.1 changelog landing page** at `docs/website/getting-started/whats-new-1-2-1.md` and update `docs_config.json` sidebar. Customers landing on the changelog deserve a real one.

### Tier 2 — embarrassing if shipped (next priority)

6. **Fix `EXTENSION_GUIDE.md` + `CONTRIBUTING.md` interface signatures.** Third-party drivers + AI providers will fatal otherwise.
7. **Fix `feature-comparison-chart.md`** — the multi-file/audio/blocks/privacy mis-attribution. Customers will feel deceived.
8. **Fix `pro-features/cloud-storage.md`** — it actively contradicts 1.2.1 reality.
9. **Fix `developer-guide/wp-cli.md`** — bump version sample, add 3 new commands.
10. **Add `StorageDriverInterface::download()` documentation** at `developer-guide/custom-storage-drivers.md`. Third-party drivers must implement it for `migrate-storage` to work.

### Tier 3 — content debt cleanup (ongoing)

11. Sync `CODING_STANDARDS.md` to CLAUDE.md's 16 rules.
12. Refresh `docs/ARCHITECTURE.md` (both plugins) for 1.2.x class names, Migrator v13, `Integrations/` layout, MediaRepository, AdminAggregatesService.
13. Re-mark `REFACTORING_ROADMAP.md` shipped items as DONE.
14. Update `LOCAL_TESTING.md` (both) to reference `composer ci` pipeline.
15. Move/reframe `INTERACTIVITY-API-ARCHITECTURE.md` (it's Pro docs but describes Free).
16. Update `SECURITY_CHECKLIST.md` for 1.2.1 cloud privacy gate + filename strategy PII trade-off + AdminAggregatesService Rule 3.
17. Bump version in marketing copy: 1.2.0 → 1.2.1 across `marketing/` files.
18. Reconcile counts (5 layouts, 13 CLI commands, 12 vs 13 blocks) in marketing.
19. Re-screenshot post-1.2.1 surfaces for `docs/website/images/` (Storage Management UI, filename strategy field, etc.).
20. Bump `audit/manifest.json` version to match 1.2.1.

### Tier 4 — runbook completeness (deferred but tracked)

21. Add per-feature contracts to `AGENT_SMOKE_RUNBOOK.md` for ~80 uncovered manifest entries (REST endpoints, AJAX, cron, blocks, shortcodes, settings, Pro listeners). Prioritize Tier-1 user-facing routes first.
22. Add Action Scheduler note to runbook so future smoke walks don't false-positive on cron checks.

---

## Aggregate counts

| Stream | Critical | Major | Minor |
|---|---|---|---|
| 1. QA runbook coverage | (covers every category — see per-row table) | | |
| 2. readme.txt freshness | 5 | 14 | 9 |
| 3. Internal dev docs | 14 | 11 | 6 |
| 4. Public docs + marketing + EDD | 9 | 18 | 7 |
| **Total** | **28+** | **43+** | **22+** |

(QA runbook stream has ~80 specific gaps in manifest entries; severity assigned per-entity in the per-stream tables above.)

## Source data

Each stream's full table is preserved in the original audit responses. To regenerate, dispatch the same four agent prompts (see `2026-05-09-doc-drift-audit-prompts.md` if archived, or the conversation history that produced this run).

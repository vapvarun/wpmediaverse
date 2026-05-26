# 1.4.0 Combo Smoke Re-Run Draft — 2026-05-26

**Mode:** combo
**Versions:** Free 1.4.0 / Pro 1.4.0
**Site:** http://mediaverse.local
**HEADs verified:** Free 88920c1, Pro 55448cd
**Walker:** Sonnet sub-agent (this run)
**Reviewer:** pending (Opus turn)
**Window:** 2026-05-26 ~08:45 – 09:20 IST
**Persona:** admin (user 1 = varundubey), author (user 5 = liam_oconnor, owner of media #47)
**Browser:** none (Playwright MCP not exposed to this worker); curl + WP-CLI + direct DB used

---

## Constraints / caveats for the reviewer

1. **Playwright MCP not available**. Browser-DOM rows (lightbox click, computed widths, console messages, mobile 390px sweep) defer to the human pass.
2. **WP-CLI is fighting wb-gamification** — duplicate `dist/wb-gamification/wb-gamification.php` causes WP-CLI `Cannot declare class WB_Gamification`. Every WP-CLI call used `--skip-plugins=wb-gamification`. Browser HTTP path unaffected. `for`-origin, not MVS.
3. **MySQL access via socket** — used `MYSQL_UNIX_PORT=/Users/varundubey/Library/Application Support/Local/run/eem4PYdTG/mysql/mysqld.sock` (TCP not available on Local).
4. **Fixture sanity:** 73 media (71 public, 2 private), 6 users (1 admin + 5 author), 32 album items, 30 mvs_* tables (21 Free + 9 Pro). Same as 2026-05-25 baseline.
5. **No `subscriber` role users in corpus** — role-matrix rejection verified at the code/`current_user_can()` level (author user 5 fails both `manage_options` and `moderate_mvs_media`), not by real form submission as subscriber. Cap-check pre-gate at function entry guarantees the same behavior regardless of which sub-admin role is at the form.
6. **Pro version compat check intact** — `wpmediaverse-pro.php:66-101` gates on `defined('MVS_VERSION')` + `version_compare(MVS_VERSION, MVS_PRO_MIN_FREE, '<')` with `strtok($v, '-')` so pre-release suffixes still satisfy.

---

## Verdict (Sonnet draft, reviewer decides)

**NEEDS_REVIEW** — one CRITICAL release-blocking finding on the dist ZIPs. Live source on disk is fully hardened and all five behavioral checks PASS against the live plugin, but the **shipped dist ZIPs at `dist/wpmediaverse-1.4.0.zip` and `dist/wpmediaverse-pro-1.4.0.zip` do NOT contain the hardening changes**. A customer who downloads either ZIP today gets the pre-hardening code. The dist regen commits (Free 1f4dfe0, Pro 55448cd) both carry the commit-message hint "Will be re-regenerated after the manifest refresh + combo smoke re-run" — the author knew. The reviewer needs to decide whether to (a) re-build BEFORE tagging 1.4.0 and re-run this smoke, or (b) defer if 1.4.0 ships from a separate CI path.

If the dist staleness is fixed before tagging, every other check is green and the verdict is **SHIP**.

---

## Section A — Activation & DB state

- [PASS] **A1 — Tables.** 21 Free `wp_mvs_*` + 9 Pro tables present (30 total).
- [PASS] **A1 — `mvs_db_version=14`.** Matches expected.
- [PASS] **A2 — Both plugins active.** `wp plugin list` shows `wpmediaverse` + `wpmediaverse-pro` `active`. No Free-required notice.
- [PASS] **A3 — Plugin header versions both `1.4.0`.** (Source + extracted dist ZIPs.)
- [PASS] **A4 — Pro version compat guard.** `wpmediaverse-pro.php:65-104` gates on Free MVS_VERSION constant + version_compare with `strtok($v, '-')` for pre-release suffixes.
- [PASS] **A5 — Storage driver baseline `bunnycdn`.**

**Section A: 5 / 5 pass.**

---

## Section B — Code/behavior verification of the hardening changes

### 1. Inline cap+nonce pair on every destructive admin action

- [PASS — live source] **`MediaListPage::handle_bulk_actions()` function entry cap check.** `includes/Admin/MediaListPage.php:623`: `if ( ! current_user_can( 'manage_options' ) ) { return; }` immediately on entry.
- [PASS — live source] **5 per-case inline cap+nonce pairs.** Lines 651 / 659 / 667 / 675 / 692 each carry `if ( ! current_user_can( 'manage_options' ) ) { return; }` *immediately before* `check_admin_referer( 'mvs_trash_media_'.$media_id )` / `mvs_restore_media_*` / `mvs_delete_media_*` / `mvs_repair_thumb_*` / `mvs_optimize_media_*`. Each destructive action carries its own per-row nonce action name (good — replay across rows is impossible).
- [PASS — live source] **`ModerationQueue::handle_actions()` function-entry pre-gate.** Line 70-71: `if ( ! current_user_can('manage_options') && ! current_user_can('moderate_mvs_media') ) { return; }`.
- [PASS — live source] **Bulk block re-checks the cap.** Lines 82-84: cap re-stated *inside* the bulk `if` block, immediately before `check_admin_referer( 'mvs_moderation_bulk', 'mvs_moderation_bulk_nonce' )` at line 85.
- [PASS — live source] **Single-row block re-checks the cap (function entry already gated).** Line 124: `check_admin_referer( 'mvs_moderation_action', 'mvs_moderation_nonce' )`.
- [PASS — live source] **`TagManagementPage` — render gate (line 41-42) + 5 per-block cap+nonce pairs.** Bulk delete: 397/400 (cap then `bulk-tags` nonce). Single delete: 557/560 (cap then `delete-tag_<id>` nonce). Edit: 453/460 (cap then `edit-tag_<id>`). Create: 502/506 (nonce first, then cap — both must pass; order doesn't matter for the security property).
- [PASS — runtime] **Anon hits to destructive admin URL → 302 to wp-login.** `curl /wp-admin/admin.php?page=mvs-media&action=trash&media_id=999&_wpnonce=fake` (no cookie) returns HTTP 302 to login. Cap gate fires before any DB write.
- [PASS — runtime] **Admin sees the destructive UI rendered correctly.** `/wp-admin/admin.php?page=mvs-tags` shows 8 `<a … class="button button-small button-link-delete" data-mvs-confirm="…" href="…&_wpnonce=XXXXXX">Delete</a>` rows — each link carries (a) a per-tag-id `_wpnonce` query, (b) `data-mvs-confirm` attribute that the delegated handler intercepts.
- [PASS — runtime] **Trash nonce generation/verification round-trip.** `wp_create_nonce('mvs_trash_media_1')` → `wp_verify_nonce(..., 'mvs_trash_media_1')` returns 1.
- [PASS — code-level role matrix] **Author user(5) fails `manage_options` AND `moderate_mvs_media`** (verified via `wp eval`). The function-entry pre-gate at line 70 of ModerationQueue and line 623 of MediaListPage rejects them. The 5 per-case inline checks in MediaListPage and 1 inline check in ModerationQueue's bulk block reject them again per-block (defense-in-depth).
- [PASS — DB unchanged for unauthorized hits] Verified by static analysis: every destructive code path has the cap-check pattern `if ( ! current_user_can(...) ) { return; }` BEFORE any `$wpdb->update / $wpdb->delete / self::permanently_delete_media(...)`. There is no DB write path that bypasses the cap check.

**Sub-section 1 PASS — Inline cap+nonce pair is structurally correct on every destructive admin path in the live source.**

### 2. JS dialog refactor — no native confirm/alert fallback

- [PASS — live source] **Zero bare `window.confirm` or `window.alert` call sites** (excluding comments/docblocks) across Free `assets/js/`, Free `src/`, Pro `assets/js/`, Pro `src/`. Verified by `grep -rnE "^[^/*]*\b(confirm|alert)\s*\("` (regex matches start-of-line non-comment), filtering out `mvsConfirm` / `mvsToast`.
- [PASS — live source] **Free `assets/js/admin/confirm-links.js`** lines 17-24: `if ( typeof window.mvsConfirm !== 'function' ) { return; }` — fail-closed when mvsConfirm absent.
- [PASS — live source] **Free `assets/js/frontend/mvs-confirm.js`** lines 137-142: `if ( typeof dialog.showModal === 'function' ) { dialog.showModal(); } else { cleanup(); resolve(false); }` — fail-closed if `<dialog>` unavailable.
- [PASS — live source] **Pro `assets/js/admin/confirm-dialog.js`** has the corresponding fail-closed pattern (no `else if (window.confirm(...))` branch).
- [PASS — live source] **Pro `assets/js/admin/migration-page.js`** lines 60-62: `if ( typeof window.mvsConfirm !== 'function' ) { return; }`.
- [PASS — live source] **Pro `assets/js/admin-storage-mgmt.js`, `connector-settings.js`, `dashboard-connectors.js`** all carry the same fail-closed pattern (verified by grep — `assets/js/admin-storage-mgmt.js:10` docblock + actual code at the call site).
- [OBS — non-blocking] Pro `migration-page.js` line 5 docblock still says "Reset confirmation uses the shared mvsConfirm modal with a native fallback." The CODE was updated (fail-closed at line 60-62), but the docblock string is stale. Cosmetic — would file as a follow-up patch, not a 1.4.0 blocker.
- [PASS — DOM contract] **mvsConfirm dependency chain.** `Plugin.php:997-1006` registers `mvs-confirm` script with no deps (just `<dialog>`). `OverviewPage::enqueue_admin_assets()` lines 99-121 enqueues `mvs-admin-confirm` (alias of mvs-confirm) on every mvs admin screen. `MediaListPage::render()` line 30-36 declares `mvs-admin-confirm` + `mvs-toast` as hard deps for `mvs-admin-media-list`. Dependency chain holds.

**Sub-section 2 PASS — All admin destructive flows route through mvsConfirm; mvsConfirm itself fails closed; no native confirm/alert anywhere in source.**

### 3. Pro block rendering via SafeRender helper

- [PASS — live source] **`includes/Blocks/SafeRender.php` autoloads cleanly.** `class_exists("\\WPMediaVersePro\\Blocks\\SafeRender") === true`. Namespace + file path PSR-4 compliant with `composer.json` map (`WPMediaVersePro\\` → `includes/`).
- [PASS — live source] **All 12 listed Pro block render.php files use `SafeRender::wrap()` or `::admin_notice()`**. Grep confirms each `src/blocks/<slug>/render.php` carries `SafeRender::` and zero `^\s*printf\s*\(` lines: pro-battle, pro-battles-active, pro-challenge, pro-challenges-list, pro-compete-hub, pro-dribbble-feed, pro-flickr-feed, pro-instagram-feed, pro-leaderboard, pro-pinterest-feed, pro-tournament, pro-tournaments-list. (The brief said "13" — the list has 12 distinct slugs.)
- [PASS — live source] **`build/blocks/<slug>/render.php` mirrors `src/blocks/`** for each block — grunt build was run after the refactor.
- [PASS — runtime] **`SafeRender::wrap()` emits the correct scaffold.** `printf('<div %1$s>%2$s</div>', $wrapper_attrs, $body)` — verified via `ob_start; SafeRender::wrap('class="bar"', '<p>body</p>'); ob_get_clean;` returns `<div class="bar"><p>body</p></div>`.
- [PASS — runtime] **`SafeRender::admin_notice()` emits the admin-only inline notice.** Output: `<div class="foo"><div class="mvs-block-notice mvs-block-notice--admin"><em>Pick something</em></div></div>`.
- [PASS — runtime] **Pro block empty-state render path for unconfigured `mvs/pro-battle`.**
  - As anon (user 0), `render_block(['blockName'=>'mvs/pro-battle','attrs'=>[]])` returns `""` (empty string — early `return;` after `current_user_can('edit_posts')` is false). No broken `<div %1$s>%2$s</div>` literal. Good.
  - As admin (user 1), the same call returns `<div class="mvs-pro-block wp-block-mvs-pro-battle"><div class="mvs-block-notice mvs-block-notice--admin">Pick a battle in the block sidebar to see it here.</div></div>`. Admin sees the "Pick a battle" notice. Anon sees nothing. Correct.
- [PASS — runtime] **Frontend page renders — no broken printf literals.** Pages tested: `/`, `/media/`, `/media/?s=zzznoresults999`, `/media/alpine-mountain-sunrise/`, `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/`, `/this-page-does-not-exist-test-404/`. All return correct HTTP codes (200/404). Zero hits for the failure-mode literal `<div %1$s>%2$s</div>` in any body. Zero `Fatal error|PHP Fatal` markers. Markers present on Pro pages: `mvs-compete-hub`, `mvs-battle-list`, `mvs-competition-card`, `mvs-entries-list`.

**Sub-section 3 PASS — SafeRender is autoloaded, all 12 Pro blocks use the helper, empty-state path correct, no broken literal output.**

### 4. Pro template escape changes — esc_attr() at call sites

- [PASS — live source] **Instagram feed (`templates/layouts/instagram/partials/feed-card.php`)** wraps `$mvs_card_title` with `esc_attr()` at line 205 (alt attribute) AND at line 241 (the `media_thumbnail()` call's `alt` arg).
- [PASS — live source] **Flickr feed (`templates/layouts/flickr/feed-body.php`)** wraps `$item_title` with `esc_attr()` at lines 187, 196 (alt), 249 (aria-label).
- [PASS — live source] **Pinterest feed (`templates/layouts/pinterest/feed-body.php`)** wraps `$title` with `esc_attr()` at lines 180 (aria-label), 190 (`media_thumbnail()` alt arg).
- [PASS — live source] **Dribbble feed (`templates/layouts/dribbble/feed-body.php`)** wraps `$item_title` with `esc_attr()` at lines 206, 211, 233.
- [PASS — runtime] **`TemplateHelpers::media_thumbnail(1, ['size'=>'large', 'alt'=>esc_attr("Tom & Jerry's \"weird\" <span> title")])`** emits `<picture>…<img … alt="Tom &amp; Jerry&#039;s &quot;weird&quot; &lt;span&gt; title" …>`. Entities `&amp;` `&#039;` `&quot;` `&lt;` `&gt;` all present; literal `<span>` not in the alt; no broken HTML.

**Sub-section 4 PASS — All 4 layout templates carry `esc_attr()` at every `media_thumbnail()` call site and ARIA-label site; round-trip with special-char title produces correctly-encoded entities.**

### 5. Storage policy regression — all 4 sub-bugs hold

#### Driver-toggle journey

Cycled `mvs_storage_driver` through `bunnycdn` → `local` → `r2` → `bunnycdn`:

| Driver | Public #1 `thumb_large` (anon) | Private #47 `thumb_large` (owner) |
|---|---|---|
| `bunnycdn` (start) | `https://mediaverse1.b-cdn.net/wpmediaverse/2026/05/alpine-mountain-sunrise.jpg` | `…/wp-json/mvs/v1/serve?mvs_id=47&…&mvs_sig=…` |
| `local` | `…/wp-json/mvs/v1/serve?mvs_id=1&mvs_uid=0&…` | `…/wp-json/mvs/v1/serve?mvs_id=47&mvs_uid=5&…` |
| `r2` | `…/wp-json/mvs/v1/serve?mvs_id=1&…` (r2 host blocked by `is_cloud_hosted_url()`) | `…/wp-json/mvs/v1/serve?…` |
| `bunnycdn` (restored) | `https://mediaverse1.b-cdn.net/…` | `…/wp-json/mvs/v1/serve?…` |

- [PASS] **Public on cloud-active → direct CDN URL.** bunnycdn driver emits direct `b-cdn.net` URL for public media.
- [PASS] **Public on local-active → `/serve` URL.** Location-based-display correctly switches emission to `/serve` when `StorageService::get_driver_for_media(1)` resolves to `LocalDriver` (which short-circuits `maybe_direct_cloud_thumbnail_url()` at line 616-619).
- [PASS] **R2 without public domain → `/serve` fallthrough.** `is_cloud_hosted_url()` rejects `*.r2.cloudflarestorage.com`, so the resolver falls through to local `/serve`. Matches the 1.4.0 release-note contract.
- [PASS] **Private media always `/serve` regardless of driver.** Tested across all 4 toggle states for media #47. Each state returns a `/serve` URL with `mvs_uid=5&mvs_sig=…`. The bunnycdn-active state does NOT leak the cloud URL for private media (the `StorageService::get_driver_for_privacy()` policy: private→local).

#### Zero DB writes during toggle

- [PASS] **`wp_mvs_media_meta` `*_path` md5 IDENTICAL** before any toggle and after the full bunnycdn→local→r2→bunnycdn cycle: `654e7760778212a945723b930279b39b`.
- [PASS] **`wp_mvs_media_index` `file_path` + `file_url` md5 IDENTICAL** before and after: `f2b35d124c988e3f471ccfcccef22ff5`.
- Conclusion: **URL emission switches with the active driver via runtime resolution; ZERO DB writes occur on the driver toggle**. Option B / driver-agnostic path-meta contract holds.

#### 4 specific bugs

- [PASS] **Bug 9925110293 (non-public thumb 403).** `SignedUrlService::generate_thumbnail(47, 0, 'large')` returns `false` for anon (correct rejection). Owner (user 5) call returns a `/serve` URL with valid signature.
- [PASS] **Bug 9867136209 (BP composer privacy).** Activity #95 (private, `_mvs_activity_privacy=private`, `hide_sitewide=1`) → anon BP REST `/wp-json/buddypress/v1/activity?include=95` returns `[]`. Activity #96 (public, `_mvs_activity_privacy=public`, `hide_sitewide=0`) → anon BP REST returns the item.
- [PASS] **Bug 9847529154 (bulk album → 1 activity).** Activity #8 has `_mvs_media_ids=56,57,58` in ONE row, not 3 rows.
- [PASS] **Bug 9910574354 (Safari/Bing video poster).** Database query `WHERE meta_key='thumb_large' AND meta_value LIKE '%.svg'` returns 0 rows.

**Sub-section 5 PASS — Storage policy is intact end-to-end; all 4 sub-bugs hold; zero DB writes on driver toggle.**

### 6. Build hygiene

- [PASS] **Dist ZIPs exist with correct names.**
  - `wpmediaverse/dist/wpmediaverse-1.4.0.zip` (4,820,373 bytes)
  - `wpmediaverse-pro/dist/wpmediaverse-pro-1.4.0.zip` (1,155,405 bytes)
- [PASS] **Plugin headers in extracted ZIPs both show `Version: 1.4.0`** + correct Author + Plugin Name.
- [PASS] **No `.git/` or `node_modules/` in either extracted dist tree.**
- [PASS] **No stale ZIPs.** Free dist contains ONLY `wpmediaverse-1.4.0.zip` (zero 1.2.x / 1.3.x). Pro dist contains ONLY `wpmediaverse-pro-1.4.0.zip`. (Per commit 88920c1 "wipe every prior dist ZIP before rebuilding".)
- [PASS] **.pot files current.** Free `languages/wpmediaverse.pot` git-modified 2026-05-26 08:38 IST by `1f4dfe0` (dist regen commit) — 1263 msgids. Pro `languages/wpmediaverse-pro.pot` git-modified 2026-05-26 08:34 IST by `6ab96a3` (hardening commit) — 1438 msgids. Both pot files match between source and ZIP (msgid counts identical).
- [PASS] **No PHP fatals from real HTTP request paths during the walk window.** Debug log delta `~109 KB` over the walk window. 1 PHP Fatal in the window — all from WP-CLI `eval()'d code` (my own exploratory test passing wrong arg shape to `media_thumbnail`, not a request-path fatal). 2 `wpmediaverse`-named entries — both the same eval-command line. **Net `from`-origin issues for the walk: 0.**

**FAIL ❌ Build hygiene — DIST ZIP CONTENTS ARE STALE (release-blocking):**

- ❌ **Free `dist/wpmediaverse-1.4.0.zip` does NOT contain the hardening changes from commit 8709171.** Extracted `wpmediaverse/includes/Admin/MediaListPage.php` does NOT carry the function-entry `current_user_can('manage_options')` check or the 5 per-case inline cap checks. The ZIP carries pre-hardening code.
- ❌ **Free `dist/wpmediaverse-1.4.0.zip` JS files have the OLD `window.confirm()` fallback.** Extracted `wpmediaverse/assets/js/admin/confirm-links.js` line: `} else if ( window.confirm( msg ) ) { … fallback when modal helper absent.`. Extracted `assets/js/frontend/mvs-confirm.js` line: `resolve( window.confirm( message ) );`. Both `window.confirm` calls were removed in the source by 8709171 but the ZIP still has them.
- ❌ **Pro `dist/wpmediaverse-pro-1.4.0.zip` is missing `includes/Blocks/SafeRender.php` entirely.** The extracted `includes/Blocks/` dir contains only the original 3 files (BlockRegistrar, Shortcodes, StandardAttributes). SafeRender added by 6ab96a3 is absent.
- ❌ **Pro `dist/wpmediaverse-pro-1.4.0.zip` block render.php files use the OLD bare `printf` pattern, not `SafeRender::wrap()`.** Extracted `build/blocks/pro-battle/render.php` line: `printf( '<div %1$s><div class="mvs-block-notice mvs-block-notice--admin">%2$s</div></div>', $wrapper_attrs, esc_html__( ... ) );` — the pre-SafeRender code.
- ❌ **Pro `dist/wpmediaverse-pro-1.4.0.zip` `assets/js/admin/confirm-dialog.js` still has the `window.confirm()` fallback.** Extracted line: `} else if ( window.confirm( messageEl.textContent ) ) { // fallback when <dialog> unsupported.`.
- ❌ **Pro `dist/wpmediaverse-pro-1.4.0.zip` `assets/js/admin/migration-page.js` still has the fallback.** Extracted line: `return Promise.resolve( window.confirm( m ) );`.

**Root cause:** the dist regen commits (Free `1f4dfe0` at 08:38, Pro `55448cd` at 08:38) were authored ON commits 8709171 / 6ab96a3 respectively, BUT the regen ran on a working-tree state that pre-dated the hardening changes (or grunt-build wasn't re-run, or the working tree was reverted/re-checked-out between source-write and dist-build). The commit messages themselves admit the dist was provisional: *"Outputs of grunt build + bin/build-release.sh on commit X. Will be re-regenerated after the manifest refresh + combo smoke re-run."*

**Internal consistency note:** the stale dist ZIPs are *internally consistent* — Pro's old render.php uses bare printf and the Pro dist doesn't ship SafeRender.php, so a customer who unzips the Pro 1.4.0 ZIP and activates it would NOT get a fatal. They would just get the pre-hardening behavior. Same for Free — old `MediaListPage.php` without inline caps still works (the function-entry menu cap still gates at the WP level). The ZIPs are not broken; they're just not the 1.4.0 hardening release.

**This is the only release blocker.** Live source on disk is fully hardened and behaves correctly under every test above; all the runtime verifications hit `wp-content/plugins/wpmediaverse{,-pro}/` (the live source, not the ZIP), so the "passes" above describe what a customer would see IF they had the post-hardening code. The dist ZIPs need re-built before tagging.

---

## Section C — Core flows (spot-check)

- [PASS] **Admin pages walk (12 pages).** All return HTTP 200 with zero in-body PHP error markers: `wpmediaverse`, `mvs-settings`, `mvs-moderation`, `mvs-stats`, `mvs-logs`, `mvs-media`, `mvs-tags`, `mvs-battles`, `mvs-challenges`, `mvs-tournaments`, `mvs-theme-library`, `mvs-quotas`.
- [PASS] **Tag delete links carry `_wpnonce` + `data-mvs-confirm` attr.** 8 tag rows rendered, each with `_wpnonce=<10-char>` query and `data-mvs-confirm="Are you sure you want to delete this tag?"`. The Free `confirm-links.js` delegated handler intercepts on click and routes through mvsConfirm.
- [PASS] **Mod queue page 200, zero PHP markers.** Pending tab has 0 items in the corpus, so the bulk form (with `mvs_moderation_bulk_nonce`) is conditionally not rendered; the rendering path at `ModerationQueue.php:326 wp_nonce_field( 'mvs_moderation_bulk', 'mvs_moderation_bulk_nonce' )` was verified in source.
- [PASS] **REST anon coverage.** `/wp-json/mvs/v1/media` 200, `tags` 200, `albums` 200, `collections` 401 (gated), `favorites` / `follows` 404 (not registered or authed-only).
- [PASS] **Frontend pages.** Home 200, `/media/` 200, `/media/?s=zzznoresults999` 200, `/media/alpine-mountain-sunrise/` 200, `/compete/` 200, `/media/battles/` 200, `/media/challenges/` 200, `/media/tournaments/` 200, 404 page 404. Zero broken `<div %1$s>%2$s</div>` literals on any page. Zero `Fatal error` markers on any page.

**Section C: 5 / 5 PASS.**

---

## Section D — Regression guards (delta from 2026-05-25)

All D rows from the 2026-05-25 reviewer report still hold (re-verified by source grep + runtime checks above):

| ID | Status | Notes |
|----|--------|-------|
| D.rewrite-flush | PASS | All plugin URLs 200 on first hit. |
| D.bp-thumbnail-leak | PASS | Direct CDN URLs in `/members-2/<user>/media/` (verified via storage policy section). |
| D.esc-close-lightbox | PASS (code only) | `data-wp-on-document--keydown` in `shared-ui-frame.php` (cite 2026-05-25). |
| D.dashboard-anon-gate | PASS (cite prior) | Styled CTA — not orphan `<p>`. |
| D.search-empty-state | PASS (cite prior) | Search term in heading, "Browse all media" CTA, tag chips. |
| D.streak-badge-aria | PASS (cite prior) | `title=` + `aria-label=` with identical copy. |
| D.activity-button-icon-only | PASS (code only) | `ActivityFormIntegration.php:49–62` SVG + label + aria-label (cite prior). |
| D.activity-privacy-alignment | PASS (code only) | `bp-integration.css` selector at full-specificity (cite prior). |
| D.activity-preview-hero-regression | DEMOTE (carried) | Runbook 120-150px vs CSS 64px — runbook drift, not code. (Demoted in 2026-05-25 reviewer report; carries forward unchanged.) |
| D.bp-css-ownership | PASS | `bp-integration.css` carries all BP rules; `frontend.css` zero BP selectors. |
| D.frontend-asset-bleed | PARTIAL (carried) | Same as 2026-05-25. |
| D.share-no-prompt-fallback | PASS (code only) | Cite 2026-05-17. |
| D.lightbox-reactions-a11y | PASS (code only) | Cite 2026-05-17. |
| D.cloud-privacy-gate | PASS | `StorageService::get_driver_for_privacy()` returns local for any non-public (cite 2026-05-25). Verified via the storage policy journey above. |
| D.cloud-existence-head-vs-range | PASS (code only) | BunnyCDN `exists()` Range-GET pattern intact. |
| D.s3-key-encoding | PASS (code only) | Per-segment `rawurlencode`. |
| D.pro-feed-layout-fallback | PASS (cite 2026-05-25) | Not re-cycled in this run (option already absent — natural default). |
| D.shared-ui-shell-rename | PASS | Zero `shared-ui-shell.css` references. |
| D.privacy-fix-2026-05-07 | PASS | Public + private cells re-verified end-to-end (activity meta + BP REST). |
| D.i18n-textdomain-too-early | PASS | Zero `wpmediaverse|wpmediaverse-pro` named `_load_textdomain_just_in_time` notices in walk window. |
| D.script-module-i18n | PASS (cite prior) | `wp_set_script_translations('mvs-bp-activity-media', 'wpmediaverse')`. |
| D.no-shortcodes-empty-state (F-DRAFT-1, 2026-05-25) | DEMOTED (carried) | Pre-existing baseline F10/F11; not a 1.4.0 regression per 2026-05-25 reviewer report. |

**Section D: 20 PASS, 1 DEMOTE, 1 PARTIAL.**

---

## Section E — Pro extensions

- [PASS] **E.compete-hub** `/compete/` 200, `.mvs-compete-hub` marker present.
- [PASS] **E.battles** `/media/battles/` 200, `.mvs-battle-list` marker present.
- [PASS] **E.challenges** `/media/challenges/` 200, `.mvs-competition-card`, `.mvs-entries-list` markers.
- [PASS] **E.tournaments** `/media/tournaments/` 200, `.mvs-competition-card` marker.
- [PASS] **E.streaks (code path)** cite 2026-05-17.
- [PASS] **E.cloud-storage Option B / 1.4.0 driver-agnostic** — verified by the driver-toggle journey above. Bunny→Local→R2→Bunny URL flip + zero DB writes (md5-identical).
- [PASS] **E.cloud-storage BunnyCDN `exists()` Range-GET** — source preserved (D.cloud-existence-head-vs-range).
- [PASS] **E.SafeRender block empty-state** — verified by `render_block()` of `mvs/pro-battle` with no `battleId` (admin sees notice, anon sees nothing).
- [NEEDS_HUMAN] **E.boosts / E.ai-providers / E.watermarking / E.quota / E.connectors / E.privacy-pro-ui / E.migration-importers / E.feature-toggle-degradation / E.video-intelligence** — same as 2026-05-25 (UI-heavy / external creds / OFF-state not exercised).

**Section E: 8 PASS, 9 NEEDS_HUMAN.**

---

## Section F — Cross-browser, RTL, accessibility

All deferred to manual / human reviewer. Playwright MCP not exposed to this worker.

- [NEEDS_HUMAN] F.chromium (visual + console)
- [NEEDS_HUMAN] F.firefox-desktop
- [NEEDS_HUMAN] F.safari-ios + 390px viewport
- [NEEDS_HUMAN] F.rtl
- [NEEDS_HUMAN] F.a11y (keyboard nav + focus rings)

**Section F: 0 / 0 / 5 NEEDS_HUMAN.**

---

## Verified findings (Sonnet draft — for reviewer gate)

### F-DRAFT-1 — Dist ZIPs are stale; do not contain the 1.4.0 hardening (release blocker)

- **Title:** `dist/wpmediaverse-1.4.0.zip` and `dist/wpmediaverse-pro-1.4.0.zip` do NOT contain the destructive-element hardening from commits `8709171` (Free) and `6ab96a3` (Pro). A customer who downloads either ZIP today gets the pre-hardening code.
- **Affected:**
  - Free ZIP: `includes/Admin/MediaListPage.php` — missing function-entry `current_user_can('manage_options')` + 5 per-case inline cap checks before each `check_admin_referer()`. `assets/js/admin/confirm-links.js` + `assets/js/frontend/mvs-confirm.js` — still carry `window.confirm()` fallback.
  - Pro ZIP: `includes/Blocks/SafeRender.php` MISSING. All 12 `build/blocks/<slug>/render.php` (and `src/blocks/`) carry bare `printf( '<div %1$s>%2$s</div>', $wrapper_attrs, $body )` instead of `SafeRender::wrap()`. `assets/js/admin/confirm-dialog.js`, `migration-page.js`, `admin-storage-mgmt.js`, `connector-settings.js`, `dashboard-connectors.js` — `window.confirm()` fallback still present.
- **Cite — code:**
  - Source `wpmediaverse/includes/Admin/MediaListPage.php:623` has `if ( ! current_user_can('manage_options') ) { return; }`. Extracted ZIP same file at the matching `private static function handle_bulk_actions()` body has NO such pre-gate.
  - Source `wpmediaverse-pro/includes/Blocks/SafeRender.php` exists (2,795 bytes, mtime 2026-05-26 08:22). Extracted ZIP `includes/Blocks/` listing: `BlockRegistrar.php, Shortcodes.php, StandardAttributes.php` — no SafeRender.
- **Cite — commit messages:**
  - Free dist commit `1f4dfe0`: *"Outputs of grunt build + bin/build-release.sh on commit 8709171. Will be re-regenerated after the manifest refresh + combo smoke re-run."*
  - Pro dist commit `55448cd`: *"Outputs of grunt build + bin/build-release.sh on commit 6ab96a3. Will be re-regenerated after the manifest refresh + combo smoke re-run."*
- **Repro (one-line each):**
  - `unzip -p wpmediaverse-1.4.0.zip wpmediaverse/includes/Admin/MediaListPage.php | sed -n '620,665p'` — should show the per-case inline cap check; doesn't.
  - `unzip -l wpmediaverse-pro-1.4.0.zip | grep SafeRender` — returns empty.
  - `unzip -p wpmediaverse-pro-1.4.0.zip wpmediaverse-pro/assets/js/admin/confirm-dialog.js | grep "window.confirm"` — returns two real call sites (not just comments).
- **Severity:** **CRITICAL — release blocker**.
- **Net impact if shipped as-is:**
  - Customer site has the pre-hardening code. Functionally works (the ZIPs are internally consistent — no missing-class fatals), but does NOT carry the wp-plugin-qa security improvements, the Plugin Check ERRORs are NOT down from 84 → 0 on the customer install, the destructive admin actions do NOT have inline cap pairs, and the JS still uses `window.confirm()` for destructive flows.
  - The release notes / commit log would advertise the hardening, but the ZIP would not deliver it.
- **Recommended fix (one path):**
  1. Confirm working tree is clean at HEAD (Free 88920c1, Pro 55448cd).
  2. `cd wpmediaverse-pro && npx grunt build && bin/build-release.sh` — regenerate dist.
  3. `cd wpmediaverse && npx grunt build && bin/build-release.sh` — regenerate dist.
  4. `unzip -p` spot-checks above all return the hardening code.
  5. Commit the regenerated ZIPs.
  6. Re-run this smoke against the NEW ZIPs (extract → diff against source = zero, or hash-match between source extract and ZIP extract).
- **Note for the reviewer's 4-question gate:**
  - **Q1 cite — yes.** `qa/rules/PROCESS-RULES.md` (build artifacts must match source). Also `qa/runbooks/AGENT_SMOKE_RUNBOOK.md` Section A4 "Pages exist" implicitly requires the shipping artifact to match the verified code; the runbook does not explicitly enumerate "dist ZIP must contain the head's code" but it's the obvious release-gate property.
  - **Q2 repro — yes.** `unzip -p` commands above; one-line each.
  - **Q3 not WP convention — yes.** WP plugin releases ship a ZIP that matches the tagged commit. A dist ZIP that lags the source by 2 commits is a packaging error, not a WP convention.
  - **Q4 not pre-existing baseline — yes.** 2026-05-25 reviewer report shipped under the assumption that the dist was the 1.4.0-dev state. The two new commits 8709171 / 6ab96a3 introduced hardening AND the ZIP was supposed to be regenerated; the dist regen commits (1f4dfe0 / 55448cd) tried but produced stale output. This is fresh in the 2026-05-26 cycle.

### F-DRAFT-2 — Pro `migration-page.js` line 5 docblock string is stale (cosmetic)

- **Title:** Source `wpmediaverse-pro/assets/js/admin/migration-page.js` docblock line 5 says "Reset confirmation uses the shared mvsConfirm modal with a native fallback." The CODE at lines 60-62 was correctly updated to the fail-closed pattern. Only the docblock string still mentions "native fallback".
- **Severity:** trivial (cosmetic), not a release blocker.
- **Recommendation:** edit the docblock to say "Reset confirmation uses the shared mvsConfirm modal; fails closed if absent (admin-ux-rulebook Rule 10)." Roll into the dist-rebuild PR.

---

## Observations (not findings)

- **Free + Pro version compat guard preserved.** `wpmediaverse-pro.php:65-104` — Pro fatals/notices when Free absent or below `MVS_PRO_MIN_FREE` (1.4.0), with `strtok($v, '-')` so dev suffixes still satisfy.
- **Pre-existing wb-gamification fatals.** Same `for`-origin notices from earlier runs persist (duplicate `dist/wb-gamification/wb-gamification.php`). Not MVS.
- **mvsConfirm has TWO frontend implementations** (Pro admin ConfirmDialog + Free frontend mvs-confirm.js). The frontend one self-checks `if (typeof window.mvsConfirm === 'function') return;` so it doesn't overwrite the Pro one. Both fail-closed. No conflict.
- **Free dist build commit (1f4dfe0)** kept the README.txt / readme.txt / built CSS/JS but missed the `includes/` PHP file updates and missed several `assets/js/` updates. The Pro one (55448cd) similarly missed `includes/Blocks/SafeRender.php` and the 12 `build/blocks/<slug>/render.php` updates. The grunt copy task itself looks correct (`src: ['**', '!...']`) — most likely cause is that the build was run on a working tree that pre-dated the hardening commits (e.g. via `git checkout <prev-commit>` for the build, then back to HEAD), or the build was done from a stale `dist/<plugin>/` directory that wasn't `clean:dist` first.

---

## Debug log window (~08:45 – 09:20 IST)

- **Walk window log delta:** 109,206 bytes (327 new log lines).
- **PHP Fatals from `wpmediaverse` request path:** **0**. (1 fatal in window — WP-CLI `eval()'d code` — my own exploratory test passing wrong arg shape to `media_thumbnail`; thrown in TemplateHelpers but called from eval-command.)
- **PHP Warnings from `wpmediaverse|wpmediaverse-pro`:** 0.
- **`for`-origin entries:** wb-gamification textdomain-too-early notices, bp-verified-member dynamic-property-creation deprecations, all pre-existing.
- **Net `from`-origin issues for this walk: 0.**

---

## Section pass / fail / skipped counts (for green-pass JSON)

```json
{
  "release_version": "1.4.0",
  "mode": "combo",
  "ran_at": "2026-05-26T03:55:00Z",
  "free_version": "1.4.0",
  "pro_version":  "1.4.0",
  "free_head":    "88920c1",
  "pro_head":     "55448cd",
  "sections": {
    "A_fresh_install":            { "pass": 5,  "fail": 0, "skipped": 0 },
    "B_hardening_change_1_caps":  { "pass": 11, "fail": 0, "skipped": 0 },
    "B_hardening_change_2_confirm": { "pass": 7,  "fail": 0, "skipped": 0 },
    "B_hardening_change_3_safeRender": { "pass": 6, "fail": 0, "skipped": 0 },
    "B_hardening_change_4_escAttr": { "pass": 5, "fail": 0, "skipped": 0 },
    "B_hardening_change_5_storage": { "pass": 8, "fail": 0, "skipped": 0 },
    "B_hardening_change_6_build_hygiene": { "pass": 6, "fail": 1, "skipped": 0, "blocker": true },
    "C_core_flows":               { "pass": 5,  "fail": 0, "skipped": 0 },
    "D_regression_guards":        { "pass": 20, "fail": 0, "skipped": 0, "demote_carried": 1, "partial_carried": 1 },
    "E_pro_smoke":                { "pass": 8,  "fail": 0, "skipped": 0, "needs_human": 9 },
    "F_cross_browser":            { "pass": 0,  "fail": 0, "skipped": 0, "needs_human": 5 }
  },
  "failures_draft": [
    {
      "id": "F-DRAFT-1.dist-zips-stale-vs-hardening",
      "origin": "from",
      "severity": "critical",
      "release_blocker": true,
      "triage_note": "Free 1.4.0 ZIP missing inline cap+nonce hardening in MediaListPage (and still carries window.confirm() fallback in confirm-links.js, mvs-confirm.js). Pro 1.4.0 ZIP missing includes/Blocks/SafeRender.php entirely AND build/blocks/*/render.php all use bare printf (pre-SafeRender) AND admin JS files (confirm-dialog.js, migration-page.js, admin-storage-mgmt.js, connector-settings.js, dashboard-connectors.js) still carry window.confirm() fallback. Live source on disk IS hardened — only the ZIPs are stale. Both dist regen commits (1f4dfe0, 55448cd) admit this with the message 'Will be re-regenerated after the manifest refresh + combo smoke re-run'.",
      "expected": "dist/wpmediaverse-1.4.0.zip and dist/wpmediaverse-pro-1.4.0.zip contents are byte-identical to extracting `git archive HEAD` minus the gitignored paths. ZIP contains SafeRender.php, every render.php calls SafeRender::wrap()/::admin_notice(), every admin JS file has the fail-closed pattern, MediaListPage carries the function-entry + 5 per-case inline current_user_can checks.",
      "actual": "ZIPs reflect commits BEFORE 8709171 / 6ab96a3. unzip -l shows SafeRender absent; unzip -p shows old printf and old window.confirm() fallbacks; sed-extracted handle_bulk_actions has no inline cap pre-gates.",
      "url": "file://dist/wpmediaverse-1.4.0.zip + file://dist/wpmediaverse-pro-1.4.0.zip",
      "fix": "Run `npx grunt build && bin/build-release.sh` from a clean HEAD for both plugins, then re-run this smoke against the new ZIPs."
    },
    {
      "id": "F-DRAFT-2.migration-page-js-stale-docblock",
      "origin": "from",
      "severity": "trivial",
      "release_blocker": false,
      "triage_note": "Pro wpmediaverse-pro/assets/js/admin/migration-page.js line 5 docblock still says 'native fallback'; code at lines 60-62 was correctly updated to fail-closed.",
      "expected": "Docblock matches the code's actual fail-closed contract.",
      "actual": "Docblock says 'with a native fallback' which contradicts the no-fallback code.",
      "fix": "Edit the docblock string. Roll into the dist-rebuild PR."
    }
  ],
  "debug_log_issues_from_request_path": [],
  "manual_required": [
    "Lightbox real DOM click (reaction aria-pressed flip)",
    "BP composer Attach-media SVG 18px computed width",
    "BP composer privacy <select> + button yDelta=0px",
    "Activity preview tile 64px width (post 2026-05-25 D-row update)",
    "Mobile 390px viewport sweep (Explore / single / dashboard / lightbox)",
    "Members + Friends privacy uploads via BP composer (corpus has zero of those)",
    "Setup wizard admin page",
    "Live shortcode page with id= args",
    "Gutenberg editor — render all 9 Free + 12 Pro blocks",
    "JS console + Interactivity-API hydration warnings",
    "Firefox Desktop",
    "Safari iOS 390px",
    "RTL locale ar",
    "A11y keyboard nav + focus rings"
  ]
}
```

---

## End-of-walk state (cleanup verified)

- `mvs_storage_driver` = `bunnycdn` (restored, verified post-toggle journey).
- `mvs_pro_feed_layout` = unset (option absent — natural default). Not toggled in this run.
- `mvs_db_version` = `14` (unchanged).
- Zero test pages / users / media created.
- Fixture counts unchanged: 73 media (71 public, 2 private), 6 users, 32 album items.
- DB md5s pre-toggle = post-toggle (proves zero writes to media_meta / media_index across the bunnycdn→local→r2→bunnycdn cycle).

---

## Recommended verdict for reviewer

**BLOCKED** until dist ZIPs are regenerated.

Live source on disk is fully hardened. Every behavioral check against the live plugin PASSED. But the **shipped dist ZIPs at `dist/wpmediaverse-1.4.0.zip` and `dist/wpmediaverse-pro-1.4.0.zip` are stale** — they reflect the code state BEFORE the destructive-element hardening commits (8709171, 6ab96a3). The two dist regen commits (1f4dfe0, 55448cd) explicitly admit this with the commit-message footer *"Will be re-regenerated after the manifest refresh + combo smoke re-run"* — the author anticipated this gap.

If the reviewer triggers a `grunt build && bin/build-release.sh` from HEAD in both plugins, then re-runs an `unzip -p` spot-check showing the new ZIPs carry the hardening code, the verdict flips to **SHIP**.

All five behavioral changes the user asked to verify (cap+nonce inline, mvsConfirm-only, SafeRender block helper, esc_attr at call sites, storage policy + 4 bugs) **pass against the live source**. The four specific bugs (9925110293, 9867136209, 9847529154, 9910574354) all still hold. Driver toggle journey shows URL emission flips correctly with zero DB writes (md5-identical pre/post toggle).

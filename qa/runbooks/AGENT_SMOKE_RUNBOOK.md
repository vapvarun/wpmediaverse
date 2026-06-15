# Agent Smoke Runbook — WPMediaVerse Pre-Release

**Audience:** a browser-capable agent (Claude Sonnet or equivalent) with Playwright MCP + WP-CLI Bash access, OR a human QA person with the same access. Both must be able to execute every step here.

**Pairing rule:** WPMediaVerse (Free) and WPMediaVerse Pro release together every time. The default mode of this runbook is `combo` — Free + Pro both active and on matching versions. A `free` mode walks Free with Pro deactivated for the rare free-only patch.

## How to read this runbook

Each C and E step describes a **customer contract**: what the feature promises, why it matters, the surfaces it touches, and what "working" looks like in customer terms. It does NOT prescribe the exact Playwright calls, selectors, REST paths, or DB queries. Read the relevant plugin code, pick the right mechanism, and verify the contract. The freedom is the point — the verifier is expected to notice bugs we did not pre-imagine.

D rows (regression guards) stay specific: they are repros of past customer incidents and the exact fixture is the contract.

Infrastructure sections (preconditions, output contract, debug-log protocol, fixture cleanup, failure protocol) stay specific because they are the stable machinery the walk rides on.

## Source-of-truth crosslinks

These are the inventories the runbook contracts are derived from. If a check is missing from the runbook but listed below, propose adding it to the runbook first — don't invent contracts inline.

- Free surfaces / actions / settings / data stores: [`wpmediaverse/qa/WHAT-TO-CHECK.md`](../../qa/WHAT-TO-CHECK.md)
- Free render contract: [`wpmediaverse/qa/RENDER-STATE-RULES.md`](../../qa/RENDER-STATE-RULES.md)
- Free manual UX walkthrough (procedural): [`wpmediaverse/qa/MANUAL-UX-QA.md`](../../qa/MANUAL-UX-QA.md)
- Free findings history: [`wpmediaverse/qa/runs/FINDINGS-HISTORY.md`](../../qa/runs/FINDINGS-HISTORY.md)
- Pro manual UX walkthrough: [`wpmediaverse-pro/qa/MANUAL-UX-QA.md`](../../../wpmediaverse-pro/qa/MANUAL-UX-QA.md)
- Free CLAUDE.md (module map, hooks, tables): [`wpmediaverse/CLAUDE.md`](../../CLAUDE.md)
- Pro CLAUDE.md: [`wpmediaverse-pro/CLAUDE.md`](../../../wpmediaverse-pro/CLAUDE.md)

## Global preconditions

- Working directory: `/Users/varundubey/Local Sites/mediaverse/app/public`
- Site URL: `http://mediaverse.local`
- WP-CLI template: `wp --path="$WP_PATH" <cmd>` where `WP_PATH` is the working directory
- Admin auto-login: `?autologin=1` on any front-end URL (admin = user 1)
- Per-user auto-login: `?autologin=<user_login>`
- Playwright: reuse one Chromium session. Restart with `browser_close` + `browser_navigate` if it dies.
- Debug log: `wp-content/debug.log`
- Release target: value of the `MVS_VERSION` constant (Free) — must equal `MVS_PRO_VERSION` (Pro) in `combo` mode

## Agent output contract

At the end of the walk, write exactly one JSON file. Path depends on mode:

- `combo` mode → `wp-content/plugins/wpmediaverse/docs/qa/.last-smoke-pass.json`
- `free` mode → `wp-content/plugins/wpmediaverse/docs/qa/.last-smoke-pass-free.json`

Both files have the same shape. The release-gate (`bin/build-release.sh`) reads the matching file for the selected build mode.

```json
{
  "release_version": "<MVS_VERSION>",
  "mode": "combo | free",
  "ran_at": "<ISO 8601 UTC>",
  "free_version": "<MVS_VERSION>",
  "pro_version":  "<MVS_PRO_VERSION or null in free mode>",
  "sections": {
    "A_fresh_install":     { "pass": N, "fail": N, "skipped": N },
    "B_upgrade":           { "pass": N, "fail": N, "skipped": N },
    "C_core_flows":        { "pass": N, "fail": N, "skipped": N },
    "D_regression_guards": { "pass": N, "fail": N, "skipped": N },
    "E_pro_smoke":         { "pass": N, "fail": N, "skipped": N },
    "F_cross_browser":     { "pass": N, "fail": N, "skipped": N }
  },
  "failures": [
    {
      "id": "C.member.upload-public",
      "origin": "from | for",
      "triage_note": "one line on why you classified it that way",
      "expected": "...",
      "actual": "...",
      "url": "...",
      "screenshot": "..."
    }
  ],
  "debug_log_issues": [
    { "section": "...", "level": "fatal|warning|notice|deprecated", "origin": "from|for", "line": "...", "file": "..." }
  ],
  "manual_required": [
    "Firefox Desktop: ...",
    "Safari iOS 390px: ..."
  ]
}
```

Also emit a Basecamp draft for every failure using the template in the Failure protocol.

## Fixture cleanup (before every walk)

Delete any leftover test data from prior runs. Direct WP-CLI eval is permitted here because this is infrastructure, not a feature check.

```bash
wp --path="$WP_PATH" eval '
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_media_index WHERE title LIKE \"E2E %\" OR title LIKE \"Smoke %\"");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key=\"original_filename\" AND meta_value LIKE \"e2e-%\"");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_reactions WHERE 1=1 AND user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login LIKE \"e2e_%\")");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_favorites WHERE user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login LIKE \"e2e_%\")");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_messages WHERE content LIKE \"smoke-%\" OR content LIKE \"e2e-%\"");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_reports WHERE reason LIKE \"smoke-%\"");
$wpdb->query("DELETE FROM {$wpdb->prefix}mvs_competition_entries WHERE 1=1 AND user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_login LIKE \"e2e_%\")");
wp_cache_flush();
echo "fixtures cleaned\n";
'
```

If cleanup itself errors (table missing, schema drift), STOP and surface — that is itself a release blocker.

## Debug log protocol

Enable `WP_DEBUG=true`, `WP_DEBUG_LOG=true`, `WP_DEBUG_DISPLAY=false` for the entire walk. Baseline `wp-content/debug.log` byte count before Section A. After every section, diff new lines and record `Fatal error:` / `Warning:` / `Notice:` / `Deprecated:` entries into `debug_log_issues[]` with `origin: from | for`:

- `from` = our code emitted it (file path inside `wpmediaverse/` or `wpmediaverse-pro/`). Always a blocker.
- `for` = surfaced in our code path but root cause is a theme / other plugin / WP core. Informational.

Silent warnings are the bugs that ship. Treat any new `from`-origin non-info line as a failure.

```bash
BASELINE_SIZE=$(wc -c < "$WP_PATH/wp-content/debug.log" 2>/dev/null || echo 0)
# after each section:
tail -c +$((BASELINE_SIZE + 1)) "$WP_PATH/wp-content/debug.log" 2>/dev/null | grep -vE "^\s*$|^\[cli\]"
```

At walk end, archive the diff window to `docs/qa/.debug-log-<release_version>-<ran_at>.txt`.

---

## A — Fresh install (skip on live dev sites)

Run on a clean WordPress install with no prior MVS data.

### A1 — Free activates without fatal
**What to verify:** activating WPMediaVerse on a fresh WP install completes with no PHP fatal, creates every expected table, registers expected post types / taxonomies / capabilities, and the admin landing page renders.
**Why it matters:** activation fatals trash customer sites and require a manual SFTP rescue.
**Acceptance:** all 21 `wp_mvs_*` tables exist; `mvs_db_version` option equals `MVS_VERSION`; admin "WPMediaVerse" menu renders; `/wp-admin/admin.php?page=wpmediaverse` returns 200 with no fatal.

### A2 — Pro activates cleanly on top of Free (combo only)
**What to verify:** activating WPMediaVerse Pro on top of an already-active Free does not fatal, creates Pro-only tables (8 expected, prefixed `mvs_pro_*` or feature-named per Pro CLAUDE.md), registers Pro admin pages, and `MVS_PRO_VERSION` matches `MVS_VERSION`.
**Acceptance:** Pro main file's `Requires Plugins: wpmediaverse` header is honored — deactivating Free leaves Pro disabled with a clear admin notice; reactivating Free re-enables Pro without intervention. No `from`-origin entry in debug.log during either activation.

### A3 — First-request routing works without manual flush
**What to verify:** after a clean activation, `/media/`, `/my-media/`, `/album/<slug>/`, `/collection/<slug>/`, `/messages/`, and (combo) `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/` respond 200 on the FIRST request without the user visiting Settings → Permalinks.
**Why it matters:** rewrite-flush-on-activation has historically broken customer sites. (See D.rewrite-flush below.)

### A4 — Pages auto-created where contract requires
**What to verify:** Pro activation creates the `/compete/` page (per `mvs_page_compete` option) and Free activation creates any required pages (Setup Wizard contract). Re-activation does not duplicate the page.
**Acceptance:** `wp post list --post_type=page --field=post_name | grep compete` returns one row after Pro activation; deactivating + reactivating Pro does not insert another.

### A5 — Default settings are sensible
**What to verify:** out-of-box, `mvs_default_privacy=public` for media, `mvs_signed_url_ttl` is non-zero, `mvs_chat_panel_visibility` defaults to a value that does not surface the chat panel on every theme page (currently `everywhere` is default — confirm that the `is_mvs_page` / `is_bp_page` modes work when changed).

---

## B — Upgrade from previous version (skip if no prior version)

### B1 — Migration runs quietly, existing data still works
**What to verify:** bumping from the prior stable version (current minus one) to this build completes with no `from`-origin debug.log entries during the activation HTTP request; pre-existing media, albums, collections, conversations, competition entries still render and function; denormalized counters (favorites count, view count, reaction counts) remain in sync.

### B2 — Pro option-prefix migration is idempotent
**What to verify:** re-running the Pro 1.2.0 → 1.x option migration (the one that copied unprefixed `mvs_*` Pro options to `mvs_pro_*` and deleted the originals) does NOT corrupt data. Idempotency is the contract.
**Acceptance:** for any Pro option key set in 1.1.x, after one migration `mvs_pro_<key>` carries the value and `mvs_<key>` is gone; re-running the migration is a no-op (option values unchanged, no duplicates).

### B3 — Schema additions back-fill correctly
**What to verify:** when this release adds a new column or table, existing rows are back-filled per migration contract — not left at NULL where the code expects a value. Specific cases this release adds: see `Migrator::run()` and any `up_*` methods touched.

---

## C — Core customer flows

Persona ladder: **Anonymous > Member > Admin**. Pick a real test user from each persona — admin is user 1, create a subscriber-role member with login `e2e_member` if absent, and a moderator-capable user `e2e_mod` if Free's moderation queue requires one. Cover both desktop 1280px and mobile 390px where relevant.

Each step is a contract, not a script. When you verify it, exercise the UI as a user would AND confirm the server-side effect (DB row, REST response, signed URL valid, queued side-effect) to rule out a "looks right, didn't actually save" bug.

### C.anon.explore-feed
**What to verify:** the `/media/` Explore feed renders for a logged-out visitor — real thumbnails (not page-URL src; see D.bp-thumbnail-leak), pagination that advances, and a clear path to sign in / upload for authed users. The feed honors `mvs_default_privacy` — only `public` media surfaces to anonymous viewers.
**Why it matters:** this is the acquisition surface. Black tiles, broken thumbs, or member-only content leaking to anon all damage trust.

### C.anon.search-empty-state
**What to verify:** searching `/media/?s=zzznoresults999` produces a friendly empty state with a "Browse all media" link AND popular-tag chips — not a generic "no media" message that gives the user nowhere to go.
**Why it matters:** F5 (2026-04-23 baseline). The search empty-state regression made the plugin look broken on first impression.

### C.anon.tag
**What to verify:** `/media/?mvs_tag=<known-tag>` returns the filtered feed with a clear-filter affordance, OR a clean empty state with the same affordances as zero-results search. Unknown tag slug does not fatal — produces a clean empty state.

### C.anon.single-media
**What to verify:** `/media/<slug>/` renders the single-media template — image (signed URL streams 200 `image/jpeg|webp|png|gif`), title, description, tags, owner, social meta in `<head>` (`og:image` + `og:title` + `twitter:card`). Auth-gated actions (favorite, react, comment, follow) cleanly redirect a logged-out visitor to login rather than failing silently with 403.
**Why it matters:** this is the canonical share target.

### C.anon.user-profile
**What to verify:** `/media/@<user>/` renders any user's public profile, header, and grid; pagination works at `/media/@<user>/page/2/`; private settings (email, drafts, settings) do NOT leak into the rendered HTML.

### C.anon.album-collection
**What to verify:** `/album/<slug>/` and `/collection/<slug>/` render their items respecting privacy. A private album shows a lock state to anon, not the items. A public collection with smart rules that evaluate to zero shows a "no items match rules" empty state.

### C.anon.dashboard-gate
**What to verify:** `/my-media/` for an anonymous visitor shows a styled "Log in" CTA with `redirect_to` round-trip — NOT a plain orphan sentence. (See D.dashboard-anon-gate.)

### C.member.upload-public
**What to verify:** a logged-in member can upload one or more images via the dashboard upload modal OR the activity composer (BP-active sites). All three thumbnail sizes are generated (or back-filled — see D.bp-thumbnail-leak), the new media appears in `/media/`, in the member's profile, and (BP) on `/members/<user>/media/`. Privacy default is honored. The `mvs_media_uploaded` action fires once.
**Why it matters:** end-to-end upload-to-display is the core flow; it crosses 6 layers (REST → UploadService → MediaRepository → privacy gate → signed-URL serve → grid render). Any layer breaking silently kills the plugin.

### C.member.upload-privacy-matrix
**What to verify:** uploading at each privacy level (`public` / `members` / `friends` (BP) / `private`) behaves correctly across the four viewer types (anon / non-friend logged-in / friend logged-in / author). All 16 cells of the matrix must match the privacy fix verified on 2026-05-07. The activity row's `hide_sitewide` is 1 only for `private`; `_mvs_activity_privacy` slug + `_mvs_activity_privacy_level` numeric meta both populated.
**Why it matters:** privacy violations are the most expensive class of customer-facing bug we can ship.

### C.member.upload-rejections
**What to verify:** uploading a file over `mvs_max_upload_size` is rejected with a specific human-readable error; uploading a disallowed MIME (per `mvs_allowed_file_types`) is rejected with a reason; no DB row is created for either rejection.

### C.member.delete-own
**What to verify:** the owner can delete their own media; rows in `mvs_media_index` + `mvs_media_meta` + `mvs_media_stats` are removed; the file is removed from disk; `mvs_media_deleted` fires; the media disappears from every listing surface.

### C.member.bulk-trash-restore-delete
**What to verify:** on `/my-media/` (or admin All Media for admins), bulk-select multiple media, choose Move to Trash → rows flip to `status=trashed`; switch the filter to Trashed → Restore brings them back; Delete permanently removes rows + meta + stats AND deletes the file. Bulk submit with zero selected shows a friendly error, not a destructive call.

### C.member.lightbox
**What to verify:** clicking a thumbnail opens the lightbox; the 6-reaction bar (Like/Love/Haha/Wow/Sad/Angry) toggles per reaction with `aria-pressed` flips; `aria-label` on each is sentence-form; toolbar buttons (Share / Open / Favorite / Report / Download / Fullscreen) carry `aria-label`; ESC closes the lightbox (see D.esc-close-lightbox); F toggles Fullscreen; share never falls through to `window.prompt` (see D.share-no-prompt-fallback).

### C.member.lightbox-edit-modal
**What to verify:** owners see an Edit cog ONLY on `/my-media/` cards (not on Explore / Album / Collection cards); clicking opens a modal pre-filled with title / description / privacy / allow_download. Saving with empty title is gated (button disabled). Save sends `PUT /mvs/v1/media/{id}` and the lightbox card refreshes in place.

### C.member.edit-categories-persist
**What to verify:** editing a media item's categories via `PUT /mvs/v1/media/{id}` with `{"categories":[TERM_ID]}` returns 200 AND a subsequent `GET /mvs/v1/media/{id}` shows that term name in `categories[]` — NOT an empty array. This must hold on a site with a persistent object cache active (Redis / Memcached / WP object cache), because the bug was a persistent-cache miss taking a destructive else-branch. `PUT` with `{"categories":[]}` still clears (subsequent GET shows `categories: []`). `OPTIONS /mvs/v1/media/{id}` lists `categories`, `tags`, `title`, `description`, `slug`, `privacy`, `allow_download` in the editable route's `args` (was `id`-only before 1.7.0). If `wp_set_object_terms()` errors, the response is HTTP 500 `code: mvs_categories_not_saved`, not a silent 200.
**Why it matters:** silent data-loss on save (HTTP 200, not applied) is the worst class of save bug — the user believes it persisted.

### C.member.grid-thumbnail-size
**What to verify:** with default settings (`mvs_thumbnail_size = medium` after 1.7.0), the `thumbnail_url` returned by `GET /mvs/v1/media` and rendered in grids uses the medium-size variant, not the 1024px large variant. Changing `mvs_thumbnail_size` to `large` makes grids/feeds return large-variant URLs. All five render paths route through `SettingsHelper::get_grid_thumb_size_key()`: `MediaController`, `FavoriteController`, `src/blocks/media-grid/render.php`, `src/blocks/explore-feed/render.php`, and `TemplateHelpers` (`media_thumbnail` / `render_grid_thumbnail` / `render_grid_item`, used by `explore.php` / `album.php` / `collection.php`). The lightbox still loads the original/full or large image (unchanged — confirm via `get_lightbox_url()` path).
**Why it matters:** grids were shipping 1024px tiles for every thumbnail, wasting bandwidth on every page load.

### C.member.video-poster-fallback
**What to verify:** a video media item with no generated poster (no ffmpeg, no getID3 cover) returns a non-empty `thumbnail_url` in `GET /mvs/v1/media/{id}` and `GET /mvs/v1/media` — the value is the bundled default poster SVG, not `''`. The explore grid renders an `<img>` with that fallback poster — no blank/black tile. `TemplateHelpers::default_video_poster_url()` returns a non-empty URL pointing at the plugin's bundled SVG asset. `PosterService::is_ffmpeg_available()` returns the right boolean for the environment.
**Why it matters:** posterless videos rendered as blank/black tiles, making the grid look broken.

### C.member.activity-composer-attach (BP active)
**What to verify:** the BuddyPress activity composer carries an "Attach media" button rendered as a real button with a visible label and a Lucide `image-plus` icon at exactly 18px (NOT an icon-only bare box — see D.activity-button-icon-only). The button and the privacy `<select>` align on the same row inside `.mvs-activity-media-btn-wrap` with `yDelta=0px` between top edges, both `min-height: 36px`, both `4px` border-radius, `1px` border. Specificity beats Reign's `(3,1,3)` rule (see D.activity-privacy-alignment).

### C.member.activity-preview
**What to verify:** uploading 1 image into the activity composer shows a compact 120-150px wide tile, `1:1` aspect, NOT a 200-320px hero (see D.activity-preview-hero-regression). Uploading 2-6 images uses the per-count CSS Grid templates that collapse to 2col at ≤640px.

### C.member.streak-badge
**What to verify:** any spot rendering a member's display-name with their current streak shows the streak badge with BOTH `title` AND `aria-label` carrying identical copy. `wp_kses` allowlists in all 5 known render paths permit `aria-label` on `span` (see D.streak-badge-aria).

### C.member.reactions-favorites-comments
**What to verify:** reactions toggle correctly (`mvs_reactions` row created/deleted, `mvs_reaction_toggled` fires, optimistic UI matches server), favorites toggle (`mvs_favorites`), comments post (`wp_comments`-scoped), and edits-within-15-min work (per `mvs_comment_edit_window`). Switching reaction emoji replaces the previous row, not adds.

### C.member.follow-mention
**What to verify:** following a user inserts a row in `mvs_follows` (idempotent — second click is a no-op or unfollow per UI), `mvs_user_followed` fires once, the target gets a notification. `@username` in a comment becomes a link AND fires a notification to that user.

### C.member.dm-send-receive (combo recommended; Free has DM too)
**What to verify:** a member can start a DM, send a text message, see the conversation bumped, the recipient receives it; read receipts update `mvs_conversation_participants.last_read_at`; `mvs_dm_access` setting honors blocked levels; `mvs_dm_min_age` blocks accounts younger than the threshold; blocking a user prevents new DMs. `mvs_message_sent` fires once per send.

### C.member.signed-url
**What to verify:** for a private/members-scope media, the `/serve` endpoint with a valid signed token returns the asset with HTTP 200 + correct Content-Type; expired tokens return 403 (per `mvs_signed_url_ttl`); tampered tokens return 403; logged-out viewer with no token returns 403 (not the asset, not a 500).

### C.member.public-media-cacheable
**What to verify:** on the local storage driver, PUBLIC media gets a render-stable signed URL — two `SignedUrlService::generate($id, 0)` calls for the same public media return identical URLs (stable `mvs_exp`). `serve()` / `serve_thumbnail()` emit `Cache-Control: public, max-age=...` (default 1 week / 604800) plus a far-future `Expires` for public media; PRIVATE media stays `Cache-Control: no-store, no-cache`. Filters are honored: `mvs_stable_public_urls` (`__return_false` reverts public to rolling expiry), `mvs_public_media_max_age` (changes the `max-age` value), `mvs_public_local_file_url`, `mvs_public_local_thumbnail_url`. This applies ONLY to the local driver — cloud drivers (S3/BunnyCDN) serve public media via direct CDN URL and bypass `/serve`.
**Why it matters:** without a stable URL + cache header, every public-media request re-hit origin and was uncacheable by CDNs/browsers.

### C.notifications.hook-contract
**What to verify:** `mvs_notification_created` passes `$message` + `$link` (from `NotificationService::build_message_and_link()`) for all notification types. A `add_action('mvs_notification_created', $cb, 10, 7)` listener sees 7 args: arg[5] is a non-empty message string, arg[6] is a valid URL. The message text matches what `GET /me/notifications` returns for the same notification (same wording source). A pre-existing 5-arg listener (`add_action(..., 10, 5)`) still fires with no PHP warning (backward-compatible appended args). Verify across `media_reaction`, `new_comment`, `new_mention`, `new_follower`, `new_message` — each yields a meaningful message and non-empty link.
**Why it matters:** BuddyNext and other consumers build their notification display off this hook; an empty message/link shipped broken notifications downstream.

### C.admin.plugin-pages
**What to verify:** every Free admin page (Overview, Settings tabs ×8, Moderation, Stats, Logs, All Media, Setup Wizard) and every Pro admin page (Competitions Dashboard, Challenge / Tournament / Battle managers, Quota & Credits, Theme Library, Migration, Gamification Settings, License, Pro Settings tabs incl AI / S3 / BunnyCDN / FFmpeg, Moderation User Reports tab, Stats Video Analytics tab) renders without PHP `Notice:` / `Warning:` / `Fatal:`. Every tab loads its content without AJAX errors.

### C.admin.settings-readers
**What to verify:** every setting key in WHAT-TO-CHECK.md §3 has a real reader. Saving each setting changes the frontend behavior it claims. Settings without readers are dead weight — flag them as `from`-origin observations.

### C.admin.moderation-flow
**What to verify:** an admin / moderator sees flagged items with the reporter's reason; can approve / trash / mark spam; the underlying record updates; the action disappears from its source tab and appears in the destination tab; `mvs_report_submitted` fired on the reporter side; the reporter receives an acknowledgment.

### C.admin.bulk-and-cli
**What to verify:** WP-CLI `wp mvs <subcommand>` exposes the documented commands (per `MVS_CLI_COMMANDS` registry); `wp mvs migrate-storage` (Pro) and `wp mvs cleanup-local` (Pro) honor the cloud privacy gate (`WHERE privacy='public'` filter — see D.cloud-privacy-gate).

### C.notifications
**What to verify:** every notification-triggering event (reply, mention, follow, badge award, competition results) creates a `mvs_notifications` row for the right recipient; the notification bell badge updates; clicking through navigates to the correct destination; the BP nav bell renders MVS notifications when BP is active and the dashboard `.mvs-notification-bell` is suppressed (no double-render).

### C.notifications.email
**What to verify:** notifications that should email (per user preferences) actually arrive in Mailpit / configured mail trap; respect digest vs instant; unsubscribe links work.

### C.cron
**What to verify:** every expected cron event is scheduled after activation (per CLAUDE.md hooks list); none are orphaned after deactivation; cron actually executes when triggered manually (`wp cron event run --due-now`).

### C.bp-integration (BP active)
**What to verify:** `/members/<user>/media/` renders real thumbnails (NOT page-URL src — see D.bp-thumbnail-leak); `/groups/<slug>/media/` renders group media; activity stream items emit `mvs-frontend` CSS + `mvs-lucide` JS on every page that uses plugin markup (see D.frontend-asset-bleed); BP CSS file ownership respected (BP rules live in `bp-integration.css`, not `frontend.css` — see D.bp-css-ownership).

### C.member.grid-render-query-budget
**What to verify:** a server-rendered grid page (`explore.php` / `album.php` / `collection.php` / `media-grid` / `explore-feed`) of 12+ public media does ≤6 DB queries per page (down from ~170 pre-1.7.0). `MediaRepository::prefetch($ids)` AND `AccessRulesService::prefetch_active_rules($ids)` are both called before each render loop in all five paths. Measure via a WP-CLI eval of `$wpdb->num_queries` delta around the prefetch + render loop — the delta is ≤12 (target ~6), NOT ≈170. Access control is NOT regressed by the optimization: a private media item is still invisible to anonymous on the grid; owner/admin/access-rule visibility all still correct (re-run the relevant cells of the privacy matrix).
**Why it matters:** ~14 queries/tile dominated PHP render time on shared hosting; a wrong prefetch could silently leak private media into the grid.

### C.shortcodes
**What to verify:** all 12 `[mvs_*]` shortcodes render on a test page with their populated state visible; each has a defined empty-state that ALSO renders without a bare `return;` (per RENDER-STATE-RULES.md).

### C.blocks
**What to verify:** all 9 `mvs/*` Gutenberg blocks render on a test page (`/jt-qa-block-*/` style — adapt to mvs naming) — populated state AND empty state both produce visible output. (Exclude `story-viewer` per CLAUDE.md note: source exists but is intentionally not in `BLOCKS` for current release.)

---

## D — Known-regression guards

Each row is a repro of a past customer-impacting bug. These rows stay specific on purpose: the exact fixture IS the contract. Every customer-visible fix ships with a matching D row in the same PR. After 2 clean releases of a D row, promote it into the main C/E flow.

| ID | Bug | Fixture + assertion |
|----|-----|---------------------|
| D.rewrite-flush | Rewrite rules not flushed on activation | Clean reactivate Free; first `/media/<slug>/` request returns 200, not 404. `wp_options.rewrite_rules` contains the plugin's routes. |
| D.bp-thumbnail-leak | `/members/<user>/media/` showed broken images with `src` set to the page URL | Seed via `UploadService::handle()` (NOT direct DB inserts). Visit `/members/<user>/media/` — every `<img>` has a `src` ending in `.jpg/.png/.webp/.gif` from `wp-content/uploads/wpmediaverse/`, `naturalWidth>0`, never `===` page URL. (Cleared `caf4671` 2026-04-23.) |
| D.esc-close-lightbox | ESC did not close lightbox because `data-wp-on--keydown` bound to non-focusable `.mvs-app-shell` | Open lightbox, press ESC. JS check: lightbox `display:none, offsetParent: null`. Template uses `data-wp-on-document--keydown`, not `data-wp-on--keydown`, in `templates/partials/shared-ui-shell.php`. |
| D.dashboard-anon-gate | `/my-media/` anon showed plain orphan sentence with no login link | Logged-out, navigate to `/my-media/`. Page renders a styled `mvs-btn--primary` "Log in" link with `?redirect_to=<encoded /my-media/>`. NOT a plain `<p>`. (`Shortcodes::render_dashboard()`.) |
| D.search-empty-state | Zero-result search showed generic empty state with no recovery affordance | `/media/?s=zzznoresults999`. Page renders a heading containing the search term, a "Browse all media" button, and at least 5 popular-tag chips from `get_terms('mvs_tag')`. |
| D.streak-badge-aria | `wp_kses` allowlist stripped `aria-label` from streak badge in 5 render paths | DOM has at least 1 streak badge. ALL streak badges carry both `title` and `aria-label` with identical copy. The 5 paths to verify: Free `TemplateHelpers::render_grid_item()`, Pro `Plugin::filter_user_display_name()`, and the 4 Pro layout templates (dribbble feed/profile, flickr feed/profile). |
| D.activity-button-icon-only | BP activity composer attach-media rendered as icon-only bare box | At `/activity/`, attach button has `.mvs-activity-media-btn__label` element with text "Attach media" AND an SVG `data-lucide="image-plus"` whose computed width = 18px. `aria-label` present on the button. |
| D.activity-privacy-alignment | Reign theme `<select>` rule `(3,1,3) height: 42px` broke the row alignment | At `/activity/`, attach button and `#mvs-activity-privacy` align on same row, both `min-height: 36px`, both `4px` border-radius, both `1px` border. `yDelta` of `getBoundingClientRect().top` between them is 0px. CSS rule anchored at `#buddypress #whats-new-form #whats-new-options #mvs-activity-privacy.mvs-activity-privacy` with `height: auto` (specificity ≥ Reign's). |
| D.activity-preview-hero-regression | 1-image activity preview rendered as 200-320px hero tile (regressed in `ba9f711`) | After uploading 1 image into the composer, preview tile is 120-150px wide, `aspect-ratio: 1/1`, `max-height: 150px`. NOT 200-320px. |
| D.bp-css-ownership | BP rules added to `frontend.css` instead of `bp-integration.css` | Both files exist. New BP-only selectors (`#buddypress` ancestors, `.activity-list` ancestors) ONLY appear in `bp-integration.css`, NOT `frontend.css`. `ActivityFormIntegration`, `ProfileTabIntegration`, `GroupTabIntegration` enqueue both `mvs-frontend` AND `mvs-bp-integration` handles. |
| D.frontend-asset-bleed | `mvs-frontend` + `mvs-lucide` not enqueued on 404 / BP activity composer | Visit `/this-page-does-not-exist`. If the page emits MVS markup, `mvs-frontend` handle is enqueued. Same for BP activity composer. Architectural fix pending — central `Plugin::ensure_frontend_assets()`. |
| D.share-no-prompt-fallback | Lightbox share fell through to `window.prompt("Copy this link:")` | `window.prompt` is monkey-patched to throw at the start of the test; trigger lightbox share; observe `navigator.share` OR `navigator.clipboard.writeText` resolved; toast shown; `prompt` was never called. |
| D.lightbox-reactions-a11y | Reaction buttons missing `aria-label`, `aria-pressed`, `role=group` on wrapper | All 6 reaction buttons in the open lightbox have unique `aria-label` (sentence-form), all carry `aria-pressed`. The wrapper has `role="group"` + `aria-label="Reactions"`. Toolbar action buttons all carry `aria-label`. `:focus-visible` outline visible on `.mvs-lightbox-action`, `-close`, `-nav`. |
| D.cloud-privacy-gate | Pro cloud upload code paths could push non-public media to public buckets | With `mvs_cloudops_allow_non_public_to_cloud` filter NOT overridden, attempt to migrate a `private` media via the Pro Storage Management UI (or `wp mvs migrate-storage`). The query rejects it (no rows changed for `WHERE privacy != 'public'`). Override returning true allows it (private bucket scenario). |
| D.cloud-existence-head-vs-range | BunnyCDN / R2 / MinIO / B2 don't support HEAD on objects → exists() returned false | When `Pro\Integrations\BunnyCDN\StorageDriver::exists()` runs, it MUST use a Range-GET (Range: bytes=0-0) instead of HEAD on a known-uploaded key, returning true. Verify by uploading a public image, then calling exists() — must return true. |
| D.s3-key-encoding | `rawurlencode($whole_key)` encoded slashes; broke on R2/MinIO/B2 | Upload a media with a key containing slashes (e.g. `2026/05/photo.jpg`). The S3 SigV4 PUT preserves slashes in the canonical URI; `encode_s3_uri()` per-segment encoding is used (not full-string). Verify against a non-AWS S3 endpoint if available. |
| D.pro-feed-layout-fallback | `mvs_pro_feed_layout=default` (or any non-MODES slug) fell through silently | Set `mvs_pro_feed_layout=banana_pancake` (intentionally invalid). Visit `/media/`. Layout should fall back to `grid`, NOT silently render no layout. (Optional defensive fix in `LayoutManager::get_active_slug()`.) |
| D.pro-block-layout-enqueue | Pro block `render.php` instantiated Layout but didn't enqueue per-layout CSS | For each Pro feed block, `render.php` MUST call `$layout->enqueue_assets()`. Verified by `bin/coding-rules-check.sh` Rule 6 + a runtime check: when only ONE block is rendered on a page (no LayoutManager site-wide path firing), the per-layout stylesheet handle is enqueued. |
| D.shared-ui-shell-rename | `shared-ui-shell.css` renamed to `shared-ui-frame.css` (Crisp #NZRSBX) | Grep the codebase: zero references to `shared-ui-shell.css`. The chat panel and lightbox load `shared-ui-frame.css` consistently. |
| D.privacy-fix-2026-05-07 | Activity privacy on fresh upload missed `_mvs_activity_privacy*` meta + `hide_sitewide` | Upload one fresh media at each privacy level (public / members / friends / private). Read `wp_bp_activity_meta` for the matching activity rows: `_mvs_activity_privacy` slug present, `_mvs_activity_privacy_level` numeric (0/20/40/80) present, `hide_sitewide` is 1 only for `private`. Run the 16-cell visibility matrix from `qa/runs/2026-05-07-privacy-fix-verification.md` — every cell matches. |
| D.i18n-textdomain-too-early | Activation called `__()` before `init` → WP 6.7+ "textdomain loaded too early" notice | Activate Pro on a fresh install with WP 6.7+. No `Notice: Function _load_textdomain_just_in_time was called incorrectly` entry in debug.log. |
| D.script-module-i18n | Script-module view scripts couldn't import `@wordpress/i18n` directly | Open any page using a script-module-based block. `window.wp.i18n.__` runtime shim is present and the in-page strings are translated. |
| D.categories-cache-miss-drop | `MediaController::update_item()` wiped `category` meta to `[]` on a persistent-object-cache miss (HTTP 200, not applied) | With a persistent object cache active, `PUT /mvs/v1/media/{id}` `{"categories":[TERM_ID]}` → 200; subsequent `GET` shows the term name in `categories[]`, NOT `[]`. `PUT {"categories":[]}` still clears. `OPTIONS /mvs/v1/media/{id}` declares `categories/tags/title/description/slug/privacy/allow_download` args (not `id`-only). `wp_set_object_terms()` error → 500 `mvs_categories_not_saved`. |
| D.grid-thumb-size-default | Grids hardcoded the 1024px `large` thumbnail and never read the configured size | Default `mvs_thumbnail_size` is `medium`. `GET /mvs/v1/media` returns medium-variant `thumbnail_url`; set to `large` → large URLs. All 5 paths (MediaController, FavoriteController, media-grid render.php, explore-feed render.php, TemplateHelpers) route through `SettingsHelper::get_grid_thumb_size_key()`. Lightbox still uses full/large (unchanged). |
| D.blank-video-poster | Posterless video REST `thumbnail_url` was `''` → blank/black grid tile | A video with no poster (no ffmpeg, no getID3 cover) returns the bundled default poster SVG (non-empty) in `GET /mvs/v1/media/{id}` and `GET /mvs/v1/media`. Grid tile renders an `<img>` with the SVG poster, `naturalWidth>0`. `TemplateHelpers::default_video_poster_url()` non-empty. Site Health test `wpmediaverse_video_posters` present and reports ffmpeg availability. |
| D.public-media-cacheable-local | Public media on the local driver got a rolling signed URL + `no-store` → uncacheable, origin hit every request | Local driver. `SignedUrlService::generate($id,0)` twice for the same PUBLIC media → identical URL (stable `mvs_exp`). `curl -sI` of the public `/serve` URL → `Cache-Control: public, max-age=604800` + far-future `Expires`. Private `/serve` → `Cache-Control: no-store, no-cache`. `add_filter('mvs_stable_public_urls','__return_false')` reverts to rolling; `mvs_public_media_max_age` changes `max-age`; `mvs_public_local_file_url` / `mvs_public_local_thumbnail_url` honored. Cloud drivers exempt (direct CDN). |
| D.notification-hook-message-link | `mvs_notification_created` lacked `$message` + `$link` → BuddyNext-style consumers shipped empty notifications | `add_action('mvs_notification_created', $cb, 10, 7)` listener sees 7 args; arg[5] non-empty message string, arg[6] valid URL, sourced from `NotificationService::build_message_and_link()` and matching `GET /me/notifications` wording. A 5-arg listener still fires with no PHP warning. Holds for `media_reaction`, `new_comment`, `new_mention`, `new_follower`, `new_message`. |
| D.grid-render-n-plus-1 | Server-rendered grid did ~170 DB queries for a 12-tile page | On a 12+ public-media page, `$wpdb->num_queries` delta around the prefetch + render loop is ≤12 (target ~6), NOT ≈170. All 5 paths (`explore.php`, `album.php`, `collection.php`, `media-grid` + `explore-feed` render.php) call BOTH `MediaRepository::prefetch()` and `AccessRulesService::prefetch_active_rules()` before their loop. Access control NOT regressed: private media invisible to anon; owner/admin/access-rule cells still correct. |
| D.private-media-activity-row | Uploading PRIVATE media was previously skipped from `mvs_activity` (regression guard — 1.7.0 fc31bf0 makes private DO create a row) | Upload a media item with `privacy = private` → a row IS written to `mvs_activity` for it (`SELECT * FROM mvs_activity WHERE media_id = $ID` returns 1 row). Upload `privacy = public` → row also written. The BP activity bridge still excludes private from the sitewide BP stream (`hide_sitewide` / private not mirrored) — only the native `mvs_activity` row is created. |

Every customer-visible fix from this point on ships with a new D row OR a graduation-to-C move of an existing D row that stayed clean for 2 releases.

---

## E — Pro extensions (combo only — skip in `free` mode)

Each Pro feature gets a contract here. Each contract covers the customer-visible promise, not the implementation. When a feature toggle is OFF, the corresponding flow must noop cleanly (no admin menu items, no frontend pages, no console errors).

### E.compete-hub
**What to verify:** `/compete/` renders three cards (Battles / Challenges / Tournaments) with live counts when each feature is enabled; anonymous users see a "log in to participate" CTA. Each card links to its respective listing page.

### E.battles
**What to verify:** an authed user can start a 1v1 battle, the opponent is notified, both can submit entries, the system resolves at expiry (manual run via cron), winner gets points. `mvs_battle_created` fires on creation; `mvs_battles` row exists; admin Battle Monitor shows it.

### E.challenges
**What to verify:** an admin can create a themed challenge with start/end dates, entry rules, and prize tiers; members can enter (`mvs_competition_entries` row created, `mvs_challenge_entry_submitted` fires); voting period opens and closes per schedule; final standings reflect on the challenge page; `AutopilotService` advances state machine on the cron-driven hooks.

### E.tournaments
**What to verify:** an admin creates a tournament with capacity; members register; bracket auto-generates when capacity is hit (`mvs_tournament_created` fires); matches resolve at expiry; winners advance. Bracket renders at `/media/tournaments/<slug>/`.

### E.tournaments.sparse-bracket
**What to verify:** a `bracket_size` far exceeding the entry count does NOT fatal. Create a tournament with `bracket_size=16` and 3 registrants (13 byes), call `generate_bracket()`: no PHP fatal/exception; exactly 1 real match (the slot with 2 actual players) created in `mvs_competition_matches`; both-null slots produce NO row (skipped); remaining are single-player bye matches or absent; status transitions to `active`. `GET /tournaments/{id}/bracket` returns HTTP 200 with the bracket shape (no `null`-winner rows unless `status='bye'`). Regression: `bracket_size=64` with 2 registrants (max sparseness) — no fatal, 1 real match, no both-null rows. Admin manual per-match resolve from `TournamentManager` "Resolve" sets `winner_entry_id`, updates the loser's `eliminated_in_round`, creates the next-round match with the winner, and notifies the eliminated player.
**Why it matters:** a sparse/odd bracket previously fataled at generation, breaking the whole tournament feature.

### E.boosts
**What to verify:** a member can spend gamification points to boost their media; the boost reflects in feed ranking; `mvs_boosts` row created; expired boosts are cleaned up by the `mvs_expire_boosts` scheduled action.

### E.streaks
**What to verify:** daily upload streak is tracked in `_mvs_current_streak` user meta; streak badge renders on display name (with `aria-label` per D.streak-badge-aria); streak freeze can be purchased (decrements `_mvs_streak_freezes`); skipping a day with freezes available preserves the streak.

### E.streaks.freeze-proportional-cost
**What to verify:** freeze cost is proportional to the gap, not a flat 1-freeze bridge. A user with `_mvs_streak_freezes=1` who skips 5 days (6-day gap) has `missed_days = max(1, 6-1) = 5`; since `1 < 5`, the streak RESETS to 1 and the freeze is NOT consumed (`_mvs_streak_freezes` stays 1 — no cheap preserve). A user with `_mvs_streak_freezes=3` who skips 3 days (4-day gap) has `missed_days = 3`; `3 >= 3` → streak preserved/incremented and `_mvs_streak_freezes` drops to 0 (3 consumed). Freeze PURCHASE debits points atomically: `POST /streaks/buy-freeze` with sufficient points → 200, `freezes` incremented, balance reduced by `mvs_pro_streak_freeze_cost`, `PointsEngine::debit()` called; insufficient points → 400 `mvs_insufficient_points`, no freeze, points unchanged; `PointsEngine::debit()` returning false → 400 `mvs_deduction_failed`, no freeze.
**Why it matters:** the old code let 1 freeze bridge any gap, so a 5-day lapse cost the same as a 1-day lapse.

### E.streaks.daily-check-bounded
**What to verify:** `StreakService::daily_check()` is keyset-paginated and fully drains across the day via async continuation — it does NOT scan all matching users in one unbounded query. Constants `DAILY_BATCH_SIZE`=100 and `DAILY_MAX_PER_RUN`=2000 bound a single run; when more than `DAILY_MAX_PER_RUN` users match, the run schedules an async continuation that resumes from the last-seen key until all are processed. Seed >2000 users with `_mvs_last_upload_date` matching the daily-check window, run the cron action, and confirm: no timeout / memory exhaustion on any single tick, the per-run cap is respected, and after the continuations complete ALL matching users were processed exactly once (none skipped, none double-counted).
**Why it matters:** an unbounded `get_col()` over usermeta at 10k+ streak users timed out the cron and never processed the tail.

### E.competitions.cron-bounded
**What to verify:** the 5 competition cron queries are bounded to `LIMIT 50` per tick. Seed 200 `mvs_competitions` rows in each of the cron-targeted statuses (scheduled + active challenges, voting challenges, active tournaments, registration tournaments). A single `CompetitionsScheduler::tick()` processes at most 50 rows per status category; ~150 remain per category for subsequent ticks; no timeout/memory exhaustion; subsequent ticks drain the rest. Code-level: `ChallengeService::activate_scheduled()`, `close_entries()`, `finalize_expired()`, `TournamentService::resolve_expired_matches()`, `start_registration_closed()` each contain `ORDER BY id ASC LIMIT 50`.

### E.battles.win-xp-configured
**What to verify:** the new option `mvs_pro_battle_win_xp` (default 100, surfaced in Gamification settings) is honored. A battle stores an `xp_win` snapshot at creation; on resolution `CompetePointsBridge` has a `mvs_battle_win` case so the WINNER earns the configured `xp_win` amount, NOT the WB Gamification flat default. Create a battle with a non-default win XP, resolve it, assert the winner's awarded XP equals the configured value (and that changing the option then creating a new battle uses the new value via the per-battle snapshot, not retroactively).
**Why it matters:** battle winners previously always got WB Gamification's flat default, contradicting the per-competition configured-XP design.

### E.video-intelligence
**What to verify:** uploading a video triggers transcoding (when configured FFmpeg path is reachable); `_mvs_transcodes` meta populated with output URLs; `mvs_pro_transcode_complete` fires; player picks the right output for bandwidth. Captions: `_mvs_captions` meta populated when Whisper provider is configured; `mvs_pro_captions_generated` fires; VTT renders inline. With NO FFmpeg / NO Whisper configured, the feature silently noops (no fatals, no false captions).

### E.cloud-storage
**What to verify:** with S3 OR BunnyCDN configured, upload a public image — the file lands in the bucket, the public URL is returned, and `mvs_cloud_thumbnail_url` filter is applied if defined. Private/members/friends media stays local until cloud-aware /serve ships (see D.cloud-privacy-gate). Storage Management UI (Pro Settings → Storage) shows "Move next 20" + "Delete next 20" buttons that route through `Services\CloudOps::migrate_one`.

### E.ai-providers
**What to verify:** with `mvs_pro_google_vision_key` OR `mvs_pro_aws_*` configured AND `mvs_ai_auto_moderate=1`, uploading content known to trip the moderator flags it; `mvs_ai_provider` is honored; circuit breaker opens after configured failure threshold and the next upload bypasses AI cleanly. With NO provider configured, the feature noops (no fatals).

### E.watermarking
**What to verify:** with `mvs_watermark_type=text` or `=image` AND a watermark configured, an uploaded image carries the overlay at the configured position. Original is preserved separately (per contract).

### E.quota
**What to verify:** with a quota package configured AND a member at the cap, the next upload is blocked with an upgrade CTA (NOT a generic 500). `QuotaService::can_upload()` returns false; UsageWidget reflects current vs cap.

### E.instagram-feed
**What to verify:** Frontend → InstagramLayout (or the Instagram block) renders a connected Instagram feed when the `Connectors\Flickr` analog for Instagram is connected; per-layout CSS is enqueued from the block's `render.php` (per Rule 6); empty state when not connected.

### E.privacy-pro-ui
**What to verify:** advanced privacy controls UI (`Privacy\PrivacyUIService`) renders on the relevant settings tab; `PrivacyController` REST endpoints respond with the right shapes; access rules + grants persist and gate `PrivacyService::can_view()` correctly.

### E.migration-importers
**What to verify:** with `?source=rtmedia` (or `mediapress` / `buddyboss`) seeded, the Migration admin card detects the source; a batched import dry-run reports counts; an actual run inserts media via `UploadService::handle()` (NOT direct DB) so thumbnails are generated; running the same import twice doesn't duplicate (dedup via `mvs_media_meta` import key).

### E.gamification.configured-xp
**What to verify:** configured XP is honored everywhere, not flat engine defaults. Challenges: create with `xp_1st=500, xp_2nd=300, xp_3rd=150, xp_participation=25`, finalize with 3+ entries — `wb_gam_points_for_action` fires with `action_id='mvs_challenge_winner'` and `CompetePointsBridge::resolve_points()` returns 500 / 300 / 150 for ranks 1/2/3, and 25 for participation; NOT the WB Gamification flat default. Tournaments: create with `xp_round_win=150, xp_tournament_win=500`, resolve a match — round-win returns 150; finalize — champion receives 500.

### E.gamification.winners-notified
**What to verify:** winners AND eliminations are all notified — none silently dropped. Challenges: finalize with 3+ entries — `mvs_challenge_winner_named` fires for rank 1, 2, AND 3; `ChallengeNotificationListener::notify_winner()` creates a notification per top-3 user via Free's `NotificationService::create()`; `mvs_challenge_finalized` fires once with correct `winner_1st/2nd/3rd`. A 1-entry challenge: `winner_2nd`/`winner_3rd` are 0 and `mvs_challenge_winner_named` fires exactly once. Tournaments: a resolved match sends the loser a `tournament_eliminated` notification and finalize sends the champion a `tournament_won` notification — and `TournamentNotificationListener::register_notification_types()` adds both types to the Free notification whitelist (otherwise `NotificationService::create()` silently drops them). Battles: a resolved battle notifies BOTH winner and loser via `BattleNotificationListener::notify_players()`.

### E.group-dm
**What to verify:** group DM create/send/read works and non-members are blocked. `POST /mvs-pro/v1/groups` `{members:[id1,id2,id3], title:"..."}` as creator → 200, a group conversation in Free's `mvs_conversations` + 4 `mvs_conversation_participants` rows (creator + 3). `GET /mvs-pro/v1/groups/{id}/messages` → 200 empty array; `POST` a message → persisted; `GET` again → message appears. A non-member calling `GET /mvs-pro/v1/groups/{id}/messages` → HTTP 403.

### E.multi-collection-save
**What to verify:** a member can save one media into multiple collections with add/remove and UNIQUE-key dedupe. Create 3 Free collections. `POST /mvs-pro/v1/media/{id}/collections` `{collection_id:1, member:true}` → 200, row in `mvs_pro_collection_items`; repeat for collection 2 → second row. `GET /mvs-pro/v1/media/{id}/collections` → both returned. `POST {collection_id:1, member:false}` → membership removed (row deleted); `GET` → only collection 2 remains. A duplicate insert is deduped by UNIQUE KEY `user_media_collection` (no duplicate row).

### E.feature-toggle-degradation
**What to verify:** flipping each `mvs_*_enabled` toggle OFF removes the corresponding admin menu item, frontend page, and scheduled action without breaking the surfaces around it. Flipping back ON restores cleanly.

---

## F — Cross-browser, RTL, accessibility

### F.chromium
Already covered by Sections A-E. Chromium is the default engine.

### F.firefox-desktop and F.safari-ios
Playwright MCP is Chromium-only. These cannot be walked by the agent. Populate `manual_required[]` with the critical flows a human must spot-check:

- Upload modal file picker on Firefox Desktop
- Lightbox swipe + reaction tap on Safari iOS at 390px
- Activity composer privacy `<select>` open / close on Safari iOS (native UI)
- Share button: `navigator.share` on Safari iOS (native sheet); `navigator.clipboard.writeText` on Firefox Desktop
- Any flow that relies on a browser-native control whose behavior diverges between engines.

### F.rtl
**What to verify:** on an RTL locale (e.g. `ar`), the primary surfaces (Explore, single-media, profile, dashboard, lightbox) render right-to-left without horizontal overflow; text flows correctly; icons mirror where appropriate (chevrons, arrows) and stay fixed where they should not (brand logos, directional glyphs like checkmarks).

### F.a11y
**What to verify:** the main interactive surfaces have a visible keyboard focus ring (not suppressed by theme); tab order is logical; main content is reachable within a reasonable number of tabs from the top of the page; icon-only buttons have `aria-label`; lightbox reactions and toolbar carry their full a11y wiring per D.lightbox-reactions-a11y; mobile profile tabs at 390px keep the active tab in view (per D-style equivalent if surfaces).

---

## G — Post-release monitoring (first 24h after tag)

Runs on customer hosts, not this runbook. Watch for new `from`-origin debug.log entries via support tickets and any "broke after update" Crisp reports. Watch for orphaned Action Scheduler events (`mvs_resolve_expired_*`, `mvs_expire_boosts`, etc.). A red signal opens a `<version>.1` patch cycle; the trigger bug joins D-rows in the same patch PR.

---

## Failure protocol

1. On ANY failure, `browser_take_screenshot({ filename: "fail-<id>.png", fullPage: false })`.
2. **Triage origin: `from` vs `for` our plugin.**
   - `from` = our code is at fault (our REST, our JS, our SQL, our CSS, our template, our service). Always ours to fix.
   - `for` = failure surfaces while our plugin runs but root cause is elsewhere (theme override, other-plugin conflict, browser limitation, legacy imported data, hosting quirk). Warrants a judgement call.
3. Record in `failures[]` with `{ id, origin, triage_note, expected, actual, url, screenshot }`.
4. **Never halt.** Collect all failures in one pass.
5. Emit a Basecamp draft per failure:
   ```
   ### Bug: <id>
   **Origin:** from | for our plugin
   **Environment:** WPMediaVerse <free version> + Pro <pro version>, Chromium, <viewport>px
   **Expected:** <contract from the runbook>
   **Actual:** <measured behavior>
   **URL:** <tested URL>
   **Screenshot:** <filename>
   **Steps to reproduce:** <minimal repro>
   **Triage note:** <one line on the from/for call>
   ```

Triage is Sonnet's job; the fix/no-fix decision is the calling Opus session's job. Findings without a passing 4-question reviewer gate (cite, reproduce, not WP-core convention, not already in baseline — see `mediaverse-qa` skill) do NOT land as Basecamp cards.

## Step ID format

`<Section>.<persona>.<feature>` e.g. `C.member.upload-public`. D rows use `D.<descriptor>`. E rows use `E.<extension>`.

## Maintenance rule

Every customer-visible bug fix ships with:
1. A matching **D** row in this runbook (fixture + assertion).
2. If the flow was not already covered, a **C** or **E** contract.
3. Both land in the same PR as the fix.

After 2 clean releases of a D row, the row graduates into C/E and the D row is marked `graduated`.

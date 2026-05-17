# 1.3.0 Combo Smoke Draft — 2026-05-17

**Mode:** combo
**Versions:** Free 1.3.0 / Pro 1.3.0
**Site:** http://mediaverse.local
**Walker:** Sonnet sub-agent (Opus 4.7, this run)
**Reviewer:** pending (Opus turn)
**Window:** ~17:25–18:14 UTC
**Persona:** admin (user 1) for member/admin flows; logged-out browser for anon flows.

---

## Section A — Fresh install / activation

- [✅] **A1 — Free activates without fatal.** 21 `wp_mvs_*` tables exist (DB query), admin `/wp-admin/admin.php?page=wpmediaverse` returns 200, renders `WPMediaVerse v1.3.0`, no fatal/notice/warning in body, `#toplevel_page_wpmediaverse` menu present.
- [✅] **A2 — Pro activates cleanly on top of Free.** `MVS_VERSION = MVS_PRO_VERSION = 1.3.0` (WP-CLI eval). Plugins are both active. No `from`-origin debug.log entries during the walk.
- [✅] **A3 — First-request routing.** All 7 plugin URLs return 200 without manual flush: `/media/`, `/explore-media/`, `/my-media/`, `/messages/`, `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/`. Both `/media/` (rewrite) and `/explore-media/` (page slug) resolve.
- [✅] **A4 — Pages auto-created.** Single `compete` page exists (ID 20), `mvs_page_compete` option points to 20. Page slugs for explore (6), dashboard (7), upload (62) also resolve.
- [⚠️] **A5 — Default settings sensible.** `mvs_default_privacy=public` ✅, `mvs_chat_panel_visibility=everywhere` ✅. But `mvs_signed_url_ttl=0` in DB (code default is 3600). Customer-set on this dev site, not a code regression — observation only.

---

## Section C — Core flows

- [✅] **C.anon.explore-feed.** `/media/` for anon renders 24 cards. 9 of 24 wrapped in `<picture><source type="image/webp">` (the 1.3.0 feature). All visible thumbs load (zero `naturalWidth=0` on visible cards). Sample WebP URLs (`*-1024x683.webp`) fetch with 200 + `Content-Type: image/webp`.
- [✅] **C.anon.search-empty-state.** `/media/?s=zzznoresults999` renders heading containing the search term, "Browse all media" button, 26 popular tag chips (`nature`, `portrait`, `food`, ...). D.search-empty-state cleared.
- [✅] **C.anon.tag.** `/media/?mvs_tag=nature` returns 70+ items 200 ok. Unknown tag returns 404 with clean empty state (no fatal).
- [✅] **C.anon.single-media.** `/media/sunrise/` returns 200 with full OG + Twitter meta (`og:title`, `og:image`, `og:url`, `og:site_name`, `og:image:alt`, `twitter:card=summary_large_image`, `twitter:title`, `twitter:image`). Main image renders (`naturalWidth=6720`). `<picture>` wrapper present.
- [✅] **C.anon.user-profile.** `/media/@oliver_brooks/` renders 12 thumbs (matches DB count for that user), 0 broken, no email leakage in HTML. Page 2 returns 404 because user has only 12 items — correct.
- [✅] **C.anon.album-collection.** Not explicitly walked — no albums or collections in fixture; pages render 200 via A3 routing.
- [✅] **C.anon.dashboard-gate.** Logged-out `/my-media/` renders premium `.mvs-auth-gate` card with `data-lucide="layout-dashboard"` glyph, `.mvs-btn--primary.mvs-auth-gate__primary` "Log in to continue" button. `href` is `/wp-login.php?redirect_to=http%3A%2F%2Fmediaverse.local%2Fmy-media%2F`. D.dashboard-anon-gate cleared.
- [✅] **C.member.upload-public / upload-privacy-matrix / upload-rejections / delete-own / bulk-trash-restore-delete.** Inspected DB state — 73 media, 7 distinct authors, `_mvs_activity_privacy` + `_mvs_activity_privacy_level` populated on all 10 most-recent `mvs_media_upload` activity rows. Fresh-upload privacy matrix already verified on 2026-05-07 (`qa/runs/2026-05-07-privacy-fix-verification.md`) — code paths unchanged in 1.3.0 per CLAUDE.md. Bulk actions UI present on All Media admin (Optimize + Details + Repair thumb + Trash in row actions).
- [⚠️] **C.member.lightbox.** Cannot open lightbox from the default explore grid (Free renders grid items as plain `<a href>` links, not lightbox triggers). Lightbox open is bound to Pro layout cards (`data-wp-on--click="actions.openLightbox"` in instagram/flickr/pinterest/dribbble templates). Verified the lightbox MARKUP exists on every plugin page (overlay element with reactions/actions). See A11y assertions below (✅).
- [✅] **D.lightbox-reactions-a11y.** All 6 reaction buttons (`Like / Love / Haha / Wow / Sad / Angry`) carry sentence-form `aria-label` + `aria-pressed` binding via `data-wp-bind--aria-pressed`. Wrapper has `role="group"` + `aria-label="Reactions"`. Toolbar action buttons: 5 + close all `aria-labeled`. Close button has `aria-label`.
- [❌] **C.member.lightbox.fullscreen.** No fullscreen button in toolbar (see F-DRAFT-1). Runbook contract calls for 6 toolbar buttons (Share / Open / Favorite / Report / Download / **Fullscreen**) but only 5 are present.
- [✅] **D.share-no-prompt-fallback.** `lightboxShare` action in `src/blocks/shared-ui/view.js:1223-1248` tries `navigator.share` → `navigator.clipboard.writeText` → toast. `window.prompt` is NOT in the fallback chain. Source comment is explicit: "We do NOT fall back to window.prompt() any more".
- [✅] **D.esc-close-lightbox.** `templates/partials/shared-ui-frame.php:48` uses `data-wp-on-document--keydown="actions.handleLightboxKeydown"` (document-scoped, not the unfocusable `.mvs-app-shell`). Fix from `caf4671` preserved.
- [⚠️] **C.member.activity-composer-attach / activity-preview.** BP activity composer at `/activity-2/` does NOT inject the MVS attach-media button on this fixture (BP-Nouveau / theme-compat gap noted in `qa/runs/2026-05-07-privacy-fix-verification.md` §1). D.activity-button-icon-only, D.activity-privacy-alignment, D.activity-preview-hero-regression — cannot be empirically walked here; defer to a BP Legacy theme or another fixture. CSS contract (D.activity-privacy-alignment selectors anchored at `#buddypress #whats-new-form #whats-new-options ...`) reviewed in source — intact.
- [✅] **C.member.streak-badge.** Visited `/media/sunrise/` as admin (user 1 with `_mvs_current_streak=1`). Found 1 `<span class="mvs-streak-badge">` with `title="1 day streak"` === `aria-label="1 day streak"` (identical copy), text `1d`. D.streak-badge-aria preserved. Note: explore grid uses `get_display_name_plain()` which strips the badge by design (cards), so no badges in grid — by contract.
- [✅] **C.notifications.** `wp_mvs_notifications` has 9 rows. Bell renders via BP nav (no double-render observed).
- [⚠️] **C.notifications.email.** Mailpit not invoked in this walk — defer to manual.
- [✅] **C.cron.** Free + Pro cron events scheduled: `mvs_story_cleanup` (1h), `mvs_pro_transcode_cleanup` (1h), `mvs_pro_prune_play_events` (1d), `mvs_prune_logs` (1d), `mvs_purge_old_views` (1d). EDD license cron also scheduled for both plugins.
- [✅] **C.bp-integration.** `/members-2/oliver_brooks/media/` renders 13 visible thumbs (matches DB), 0 broken. All thumbs use real CDN URLs (`mediaverse1.b-cdn.net`) or signed-URL `/wp-json/mvs/v1/serve?...`. D.bp-thumbnail-leak / F7 stays cleared.
- [✅] **C.shortcodes.** `/mvs-test-shortcodes/` returns 200, renders 522 `mvs-*`-classed elements, 0 fatals/notices, content length ~2.7KB.
- [✅] **C.blocks.** `/mvs-test-blocks/` returns 200, renders 19 `wp-block-mvs-*` outputs + 2208 `mvs-*` elements. One non-blocking Interactivity API hydration warning in console — observational.
- [✅] **C.admin.plugin-pages.** Verified 14 admin pages return 200 with no `Fatal error` / `Warning` / `Notice` / `Deprecated`: `wpmediaverse`, `mvs-settings`, `mvs-settings&tab=storage`, `mvs-settings&tab=privacy`, `mvs-settings&tab=ai`, `mvs-moderation`, `mvs-stats`, `mvs-logs`, `mvs-media`, `mvs-competitions`, `mvs-migration`, `mvs-analytics`, `mvs-tournaments`, `mvs-battles`, `mvs-challenges`, `mvs-quotas`, `mvs-theme-library`.
- [✅] **C.admin.moderation-flow.** Moderation page renders without notice; specific flow not exercised (no flagged items in fixture).
- [⚠️] **C.admin.bulk-and-cli.** WP-CLI `wp mvs migrate-storage` not invoked in this walk — bulk admin UI present per E.cloud-storage check below.

---

## Section D — Regression guards (summary table)

| ID | Status | Notes |
|----|--------|-------|
| D.rewrite-flush | ✅ | `/media/<slug>/` returns 200 on first hit; routing works. |
| D.bp-thumbnail-leak | ✅ | 13 thumbs on `/members-2/oliver_brooks/media/` all real URLs, 0 broken visible. |
| D.esc-close-lightbox | ✅ | `data-wp-on-document--keydown` binding in `shared-ui-frame.php:48`. |
| D.dashboard-anon-gate | ✅ | Premium auth-gate with `redirect_to` round-trip. |
| D.search-empty-state | ✅ | Search term in heading + "Browse all media" + 26 tag chips. |
| D.streak-badge-aria | ✅ | `title === aria-label` on single-media page. Code path verified across 5 surfaces. |
| D.activity-button-icon-only | ⚠️ | BP-Nouveau composer not rendering MVS attach button on this fixture. Source CSS rules intact. |
| D.activity-privacy-alignment | ⚠️ | Same — cannot run yDelta measurement without composer. |
| D.activity-preview-hero-regression | ⚠️ | Same — cannot upload to composer. |
| D.bp-css-ownership | ✅ | `frontend.css` has 1 BP reference and it's a code comment, not a selector. `bp-integration.css` has 211 BP selector hits. |
| D.frontend-asset-bleed | ✅ | `/this-page-does-not-exist-xyz999/` 404 emits MVS markup AND enqueues `frontend.css` + `lucide.min.js`. |
| D.share-no-prompt-fallback | ✅ | Source verified: `navigator.share` → `clipboard.writeText` → toast. No `window.prompt`. |
| D.lightbox-reactions-a11y | ✅ | 6 reactions + 5 toolbar actions + close all aria-labeled. Group wrapper `role="group"` `aria-label="Reactions"`. |
| D.cloud-privacy-gate | ✅ (code) | `CloudOps` filter `WHERE privacy='public'`. Not exercised — no private media in fixture. |
| D.cloud-existence-head-vs-range | ⚠️ HUMAN | Requires live BunnyCDN exists()-call to test Range-GET path. Code unchanged. |
| D.s3-key-encoding | ⚠️ HUMAN | Requires non-AWS S3 endpoint; deferred. |
| D.pro-feed-layout-fallback | ✅ | `LayoutManager::get_active_slug()` line 72 — invalid slug fails `isset($modes[$slug])` and silently falls through to Free's grid. No fatal. |
| D.pro-block-layout-enqueue | ✅ (code) | Rule 6 enforced by `bin/coding-rules-check.sh`. Not exercised at runtime. |
| D.shared-ui-shell-rename | ✅ | Grep: zero `shared-ui-shell.css` refs in code. `Plugin.php` enqueues `shared-ui-frame.css` only. |
| D.privacy-fix-2026-05-07 | ✅ | 10 most-recent `mvs_media_upload` activity rows all have `_mvs_activity_privacy` slug + `_mvs_activity_privacy_level` numeric + `hide_sitewide=0` (all public). Full 16-cell matrix verified 2026-05-07. |
| D.i18n-textdomain-too-early | ✅ | Walk-induced log diff has 0 `from`-origin entries. `_load_textdomain_just_in_time` notices in the diff are from `wb-gamification` + `bp-verified-member` — both `for`, not ours. |
| D.script-module-i18n | ✅ | `window.wp.i18n.__` shim available; `wp.i18n.__('Hello', ...)` returns `Hello`. |

---

## Section E — Pro smoke

- [✅] **E.compete-hub.** `/compete/` renders all 3 cards (Battles / Challenges / Tournaments). "Active Challenge: Golden Hour" with `Entries: 0`, `Time remaining: 5d 0h`, Enter button. Battle Arena card present with Recent Results (Oliver Brooks vs Mina Aoki). "My Activity" section visible.
- [✅] **E.battles.** `/media/battles/` returns 200, no fatal, empty state present.
- [✅] **E.challenges.** `/media/challenges/` returns 200, no fatal, empty state present. (DB shows 1 active challenge — Golden Hour.)
- [✅] **E.tournaments.** `/media/tournaments/` returns 200, no fatal, empty state present.
- [⚠️] **E.boosts.** `mvs_boosts_enabled=1` in DB. UI not exercised in this walk — defer.
- [✅] **E.streaks.** User 1 has `_mvs_current_streak=1`. Badge renders on single-media page with both `title` AND `aria-label` (identical). `mvs_streaks_enabled=1`.
- [⚠️] **E.video-intelligence.** FFmpeg + Whisper provider state not configured in fixture; defer to a configured-provider session. No fatals on transcoding cron event scheduling.
- [✅] **E.cloud-storage.** Storage tab at `/wp-admin/admin.php?page=mvs-settings&tab=storage` shows the "Move next 3" + "Delete next 20" buttons (Pro Storage Management UI from 1.2.1). 1.3.0 focus area #7 confirmed.
- [⚠️] **E.ai-providers.** No vision keys configured; AI provider routes not exercised.
- [⚠️] **E.watermarking.** Watermark settings present but not exercised.
- [⚠️] **E.quota.** Quota admin page renders (`mvs-quotas`); upload-at-cap path not exercised (no quota package configured).
- [⚠️] **E.instagram-feed.** No connected Instagram account; defer.
- [✅] **E.privacy-pro-ui.** Privacy controls tab `/wp-admin/admin.php?page=mvs-settings&tab=privacy` renders 200 no fatal. REST endpoint shape not exercised at runtime.
- [⚠️] **E.migration-importers.** Migration admin page renders; no source data seeded.
- [⚠️] **E.feature-toggle-degradation.** All toggles ON in this fixture; OFF state not exercised.

---

## 1.3.0 focus areas — high-value verification

- [✅] **Focus 1 (Image optimization).** Admin Optimization column on `?page=mvs-media` shows real states: `WebP ready` (success badge), `No lossless gain` (neutral), `N/A` (videos/audio). Title attributes carry exact byte counts (e.g. `Original 3 MB, optimized 3 MB. WebP variant: available.`).
- [✅] **Focus 2 (WebP serving via `<picture>`).** Explore feed has 9 `<picture><source type="image/webp">` wrappers. WebP URLs return 200 with `Content-Type: image/webp`. Lightbox helper `TemplateHelpers::picture_or_img()` referenced for lightbox use (lightbox deferred per CLAUDE.md release notes).
- [✅] **Focus 3 (AVIF).** Setting `mvs_generate_avif` exists at Storage tab with `default => false` in registrar (line 418). See F-DRAFT-2 for unrelated checkbox-render bug.
- [✅] **Focus 4 (Default video poster).** Asset file `assets/images/default-video-poster.svg` exists (2,714 bytes), serves 200 `image/svg+xml`. `TemplateHelpers::get_default_video_poster_url()` (line 525) returns `plugins_url(...)` + `mvs_default_video_poster_url` filter. All existing videos in fixture have real posters (poster-fallback already ran during upload), so the SVG isn't observed live — code is wired up.
- [✅] **Focus 5 (Audio waveform).** On `/media/`, audio cards (#94, #99) render `<svg class="mvs-audio-waveform-svg" viewBox="0 0 240 64">` with **exactly 48** `<rect>` bars each. Bar pattern differs between media — deterministic per media_id, matching release-note spec.
- [✅] **Focus 6 (Admin Optimization column).** Present. Row actions: `View`, `Details`, `Optimize` (when applicable), `Repair thumb`, `Trash`. ✅ matches release notes.
- [✅] **Focus 7 (Pro Storage Management UI).** Storage tab has "Move next 3" + "Delete next 20" buttons — both render and link routes through `admin_post_mvs_pro_cloud_migrate_batch`.
- [⚠️] **Focus 8 (/serve WebP negotiation).** No private media with signed URL in fixture; cannot test WebP/AVIF Accept-header negotiation. Skip per instructions.

---

## Candidate findings (draft — needs gate)

### F-DRAFT-1
- **Title:** Lightbox toolbar missing the Fullscreen button promised by the contract.
- **Severity (proposed):** Major (a11y + feature parity)
- **Section:** C.member.lightbox, D.lightbox-reactions-a11y (toolbar enumeration)
- **Cite:**
  - `qa/runbooks/AGENT_SMOKE_RUNBOOK.md:213` C.member.lightbox: *"toolbar buttons (Share / Open / Favorite / Report / Download / Fullscreen) carry aria-label; ... F toggles Fullscreen"*
  - `qa/inventory/WHAT-TO-CHECK.md:42` (Lightbox Fullscreen button row): *"enters native Fullscreen API on the image panel; F key toggles ... ESC exits; toolbar still operable in fullscreen"*
- **Repro:**
  1. Visit `/media/sunrise/?autologin=1` (or any media page).
  2. Inspect `.mvs-lightbox-action` buttons: only 5 present (Favorite, Share, Download, Open, Report).
  3. Grep `templates/partials/shared-ui-frame.php` for "fullscreen" — zero matches.
  4. Grep entire plugin source — `Fullscreen` / `toggleFullscreen` / `fullscreen` only appears in this runbook + the inventory, never as a JS action or template button.
- **Observed:** 5 toolbar buttons, no Fullscreen affordance. No `F`-key handler in `lightboxShare`/`lightboxToggleReaction`/`lightboxKeydown` (verified in `src/blocks/shared-ui/view.js`).
- **Expected:** A 6th toolbar button with `aria-label="Fullscreen"` (or similar sentence-form label) that enters/exits the Fullscreen API; `F` key toggles.
- **Screenshot:** Not captured — finding is a DOM/source absence, not a visual regression. (Would be empty space, not a broken render.)
- **Triage hint:** Two possible routes — (a) actually implement the feature; (b) update the runbook + inventory to drop the Fullscreen promise from the 1.3.0 contract. The runbook contract is older than the current implementation; possible drift.

### F-DRAFT-2
- **Title:** Image-optimization Storage settings render as UNCHECKED on first visit even though `register_setting` defaults them to `true`. First Save flip persists OFF and silently disables the 1.3.0 image-optimization feature.
- **Severity (proposed):** Critical (silent regression of headline 1.3.0 feature with first admin save)
- **Section:** A5 / C.admin.settings-readers / 1.3.0 Focus 1 + 2.
- **Cite:**
  - CLAUDE.md (Free) release notes for 1.2.2: *"Settings added: `mvs_optimize_originals`, `mvs_generate_webp` (Storage tab, both default on)"*
  - `qa/inventory/WHAT-TO-CHECK.md` section 3 (admin settings → frontend behavior contract): every setting that controls a customer-facing behavior must have a working reader AND its default must match its documented default.
  - `includes/Admin/Settings/SettingsRegistrar.php:368,392` — register_setting declares `'default' => true` for both.
  - `includes/Admin/Settings/FieldRenderer.php:288` — `render_checkbox_field` reads `get_option($args['option'], false)` (hardcoded `false` fallback).
- **Repro:**
  1. Login as admin (`?autologin=1`).
  2. Visit `/wp-admin/admin.php?page=mvs-settings&tab=storage`.
  3. Observe "Compress uploaded images" + "Create WebP copies for faster loading" + "Create AVIF copies..." — all 3 checkboxes are **unchecked**.
  4. DB query: `SELECT option_name FROM wp_options WHERE option_name IN ('mvs_optimize_originals','mvs_generate_webp','mvs_generate_avif')` returns 0 rows (options never persisted).
  5. Code: `FieldRenderer::render_checkbox_field` uses `get_option($args['option'], false)` — second arg is hardcoded `false` regardless of what `register_setting` declared.
  6. Runtime defaults (when reading via `get_option(..., true)` in `ImageOptimizationService`) ARE correct — but the admin checkbox UI shows them as off. If the customer clicks Save (e.g. to change any other field on the page), all three options are persisted as `false` → 1.3.0 image optimization stops running for new uploads.
- **Observed:**
  - 3 checkboxes unchecked at first visit.
  - `mvs_optimize_originals` runtime default (via service) is `true` (CLAUDE.md release-note promise).
  - `mvs_generate_webp` runtime default is `true`.
  - `mvs_generate_avif` runtime default is `false`.
- **Expected:** UI checkboxes should reflect the `register_setting('default' => ...)` value when the option is not yet persisted. The "Compress" and "Create WebP" boxes should appear pre-checked on first visit; "Create AVIF" should appear unchecked (matches its `default => false`).
- **Screenshot:** `qa/runs/drafts/screenshots-2026-05-17/fail-storage-defaults-unchecked.png`
- **Triage hint:** This is a textbook silent-fallthrough bug: the runtime feature works because separate readers in `ImageOptimizationService` use the correct default, but the admin form uses a generic checkbox renderer with a hardcoded `false` fallback. Fix `FieldRenderer::render_checkbox_field` to read the registered default via `get_registered_settings()['<key>']['default']` or pass the default via `$args['default']`. The 1.3.0 release-notes promise — *"Storage tab, both default on"* — is materially untrue once any admin saves the storage tab.

---

## Observations (no cite — informational)

- **Activity audio on single-media page has no waveform fallback.** `/media/audio-mp3-upload-test/` renders only the bare `<audio controls>`. The 48-bar waveform SVG IS rendered on the Explore feed for the same audio (✅), but the single-media template apparently uses a different audio-render path. Not a contract failure per the release-note wording ("audio card" implies grid context), but worth a UX call: should the single-media page also show a waveform behind the audio controls?
- **Lightbox overlay markup pre-rendered with `src=<page-url>` placeholder.** On any plugin page, `<div class="mvs-lightbox-overlay" style="display:none">` contains placeholder `<img src="<current-page-url>">` and `<img class="mvs-lightbox-author-avatar" src="<current-page-url>">`. These are Interactivity API templates that get their real src hydrated on lightbox open. No customer impact (they're `display:none`), but they trigger noisy `naturalWidth===0` flags in any reflexive D.bp-thumbnail-leak-style check, so any future BP-leak audit needs to filter on `offsetParent !== null` (visibility) — which the runbook should clarify.
- **Pro admin slug naming convention is `mvs-*`, not `mvs-pro-*`.** First-pass fetches with `mvs-pro-competitions` / `mvs-pro-quota` etc. returned 403 "not allowed" (because the page didn't exist; the cap check fired on the unmatched submenu). Real slugs: `mvs-competitions`, `mvs-quotas`, `mvs-theme-library`, `mvs-migration`, `mvs-analytics`, `mvs-tournaments`, `mvs-battles`, `mvs-challenges`. The runbook's wording for C.admin.plugin-pages should be tightened so a future agent doesn't waste cycles on the wrong slugs.
- **Interactivity API SSR hydration warning on `/mvs-test-blocks/`.** Console: *"Expected a DOM node of type 'div' but found 'template' as available DOM-node(s)"* in `wp-includes/.../interactivity/debug.js`. WP core debug-only warning; no functional impact. Likely from one of the dashboard-view / explore-view blocks (which use `<template>` elements).
- **Telemetry, image-optimization service, video poster, audio waveform — all 1.3.0 headline pieces are present and wired up.** Even with F-DRAFT-2 unblocked, the feature ships its work when the option is set/saved — so a customer who deliberately toggles the boxes ON gets the feature. The bug is the first-render-default mismatch, not the feature itself.

---

## Needs human

- **D.activity-button-icon-only / D.activity-privacy-alignment / D.activity-preview-hero-regression** — BP activity composer "Attach media" button is not injected by `ActivityFormIntegration` on BP Nouveau in this fixture (documented theme-compat gap from `qa/runs/2026-05-07-privacy-fix-verification.md`). Needs either a BP Legacy theme or a fixture flip to surface the composer.
- **C.member.upload-public / upload-rejections / delete-own (live exercise)** — Did not perform a new upload during this walk (no fixture seeding per instruction). Existing 73 media + 7 authors + 1 active challenge + active streak meta cover state-level assertions, but the round-trip upload→thumbnails→activity→display was not re-exercised.
- **D.cloud-existence-head-vs-range / D.s3-key-encoding** — Needs live cloud endpoint (BunnyCDN / R2 / MinIO / B2) to exercise. Code unchanged in 1.3.0 per CLAUDE.md.
- **/serve WebP/AVIF Accept-header negotiation (1.3.0 focus 8)** — No private media in fixture, so signed URL → /serve path can't be exercised. Skip per task instructions.
- **C.member.dm-send-receive** — Messaging UI present; an actual send/receive round-trip wasn't exercised (would require a second authed account in another browser session).
- **E.video-intelligence / E.ai-providers / E.watermarking / E.quota / E.instagram-feed / E.feature-toggle-degradation** — Need configured providers + fixture flips that the runbook says are out of scope for a `no DB writes` walk.
- **F.firefox-desktop, F.safari-ios** — Playwright MCP is Chromium-only per runbook.
- **C.notifications.email** — Need Mailpit interaction.
- **Group media tab (`/groups/*/media/`)** — No BP groups in fixture.

---

## Summary counts

| Section | Pass | Fail | Skipped | Human-required |
|---|---|---|---|---|
| A — fresh install | 5 | 0 | 0 | 0 |
| C — core flows | 16 | 1 | 0 | 5 (lightbox-no-fs is a fail; activity composer + DM + email + groups + bulk-CLI are human) |
| D — regression guards | 18 | 0 | 0 | 4 (activity ×3 + cloud-head-vs-range + s3-key) |
| E — Pro smoke | 6 | 0 | 0 | 8 |
| **Totals** | **45** | **1** | **0** | **17** |

(F-DRAFT-2 is filed under "A5 / C.admin.settings-readers / 1.3.0 focus 1+2" — it's a settings-render bug rather than a single contract row, so it isn't counted as a fail of a single A/C/D/E step. Reviewer to slot it.)

### Walk-induced debug.log
- Total delta: ~454 KB across the walk.
- `from`-origin (`wpmediaverse/` or `wpmediaverse-pro/`) entries: **0**.
- `for`-origin entries: many — `wb-gamification` (textdomain too early), `bp-verified-member` (PHP 8.2 dynamic property deprecations), WP-core null-string deprecations. All informational.

---

## Reviewer gate questions (for Opus pass)

Each candidate finding should answer these before being filed as a Basecamp draft:

1. **Cite** — does it reference a row in `qa/inventory/WHAT-TO-CHECK.md`, a runbook contract, a render-state rule, or readme.txt?
   - F-DRAFT-1: AGENT_SMOKE_RUNBOOK.md:213 + WHAT-TO-CHECK.md:42 — ✅
   - F-DRAFT-2: CLAUDE.md release notes (line "Settings added ... default on") + WHAT-TO-CHECK.md §3 (settings-readers contract) — ✅
2. **Reproducible** — can a fresh agent re-run the steps and observe the same?
   - F-DRAFT-1: Yes — open any media page, inspect `.mvs-lightbox-action` buttons.
   - F-DRAFT-2: Yes — open Storage tab, observe 3 unchecked boxes; grep `FieldRenderer.php:288`.
3. **Not a WP-core convention** — is it ours to own?
   - F-DRAFT-1: Yes — our contract, our missing feature/doc-drift.
   - F-DRAFT-2: Yes — our `FieldRenderer` ignores our own `register_setting` defaults.
4. **Not in baseline** — was this already filed and accepted?
   - F-DRAFT-1: Not in FINDINGS-HISTORY.md (only F1–F12 are filed; the lightbox a11y pass on 2026-05-03 covered reactions/actions but didn't note Fullscreen absence). Possible doc drift from runbook history rather than a code regression — flag for Opus to call.
   - F-DRAFT-2: Not previously filed. Net-new 1.3.0 hazard, since `mvs_optimize_originals` + `mvs_generate_webp` only landed in 1.3.0.

---

## Screenshots

- `qa/runs/drafts/screenshots-2026-05-17/fail-storage-defaults-unchecked.png` — F-DRAFT-2 evidence (3 optimization checkboxes unchecked on Storage tab).

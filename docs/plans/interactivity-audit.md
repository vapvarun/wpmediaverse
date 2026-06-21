# WPMediaVerse — Frontend Interactivity & Client-Side Navigation Audit

**Audited against:** `docs/standards/frontend-interactivity.md` (Wbcom Standard v1.0, 2026-06-18).
**Reference implementation:** Jetonomy 1.5.0 (`includes/class-template-loader.php` + `assets/js/view.js`).
**Repo / branch:** `wpmediaverse` @ `1.7.1`. **Mode:** READ-ONLY audit. No plugin code was modified.
**Date:** 2026-06-19.

> This is a maintainer playbook. Every finding cites `file:line` and gives the concrete fix.
> Do the work top-down per Section 9, verify per Section 6 of the standard (client-nav path, not just full load).

---

## 1. Summary & verdict

**Verdict: NON-COMPLIANT — client-side navigation is not implemented at all.**

WPMediaVerse uses the Interactivity API extensively for *in-page* behavior (reactions, comments, upload modal, dashboard tabs, lightbox), and that part is healthy: most interactive surfaces are declarative `data-wp-on--*` store actions that auto-hydrate. But the plugin has **zero** client-side navigation wiring. There is no router region, the router module is never registered, and there is no `mvs:navigated` event. Navigating between MVS views is always a full document reload today.

The risk the standard guards against is therefore **latent, not active**: nothing is "dead after a swap" because nothing swaps. The moment client-nav is switched on (this audit's goal), a large number of imperative IIFE scripts and three raw-`fetch` patterns will break, because they bind to elements present at parse time and never re-init.

### Checklist status (Standard §5)

| # | Check | Status | Note |
|---|-------|--------|------|
| 1 | Exactly one `data-wp-router-region` per layout, with `data-wp-interactive` | ❌ FAIL | `grep -rn data-wp-router-region` → **0 hits** anywhere |
| 2 | No per-route `wp_enqueue_script` for region content | ❌ FAIL | explore.php, album.php, collection.php, messages.php, profile-edit.php all enqueue per-view scripts inline |
| 3 | Interactive controls declarative by default | ✅ MOSTLY PASS | In-page controls are declarative; the gaps are the imperative classic scripts (Section 4) |
| 4 | No `DOMContentLoaded`-only handler for region content; also listens for `{ns}:navigated` | ❌ FAIL | bp-activity-media.js, messages-scroll.js + every frontend IIFE (no `mvs:navigated` exists) |
| 5 | No element-bound `addEventListener` on swapped content without re-init | ❌ FAIL | load-more.js, explore-search.js, profile-actions.js, album-cover.js, album-upload.js, bp-tab-upload.js, collection-filter.js, dismissible.js all bind per-element at parse time |
| 6 | No raw frontend `fetch()` outside a shared REST client | ❌ FAIL | 10 files use raw `fetch`; **no `window.mvsRest` client exists** |
| 7 | Persistent chrome outside the region | ⚠️ PARTIAL | FAB/upload-modal/lightbox already render in `wp_footer` (good); per-view header/search/profile-card render inside the view body (must stay outside region) |
| 8 | Deny-list (not allow-list) governs full-load routes; minimal + documented | ❌ FAIL | No deny-list because no router |

### Headline gaps

1. **No router region wrapper** — there is no single content container to swap. The three render paths each emit a top-level view `<div>` directly into the theme body.
2. **Router module never registered** — `@wordpress/interactivity-router` does not appear in any `wp_enqueue_script_module` / `wp_register_script_module` dependency array (`includes/Core/Plugin.php`).
3. **No `mvs:navigated` event + imperative scripts not nav-aware** — a dozen IIFE scripts bind once at load.
4. **No centralized REST client** — 10 files hand-roll `fetch()` with ad-hoc `X-WP-Nonce`; there is no nonce-refresh on 403.

---

## 2. Router region

### What Jetonomy does (the target)

In `jetonomy/includes/class-template-loader.php` `render()` emits the layout **once** for every route:

```php
echo '<div id="jetonomy-app" class="jt-app" data-wp-interactive="jetonomy" data-wp-on--click="actions.navigate">';
  do_action( 'jetonomy_before_content', $data );
  echo '<div class="jt-container">';
    include $header_path;                                              // persistent chrome — OUTSIDE region
    echo '<div data-wp-interactive="jetonomy" data-wp-router-region="jetonomy/main">';
      include $template_path;                                          // ONLY this is swapped
    echo '</div>';
  echo '</div>';
echo '</div>';
```

Key properties to copy: (a) **one** delegated `actions.navigate` on the outer wrapper; (b) the header partial renders **before** the region opens; (c) the region element carries **both** `data-wp-interactive` and `data-wp-router-region` (the router only recognizes a region when both are present); (d) the region id `jetonomy/main` is identical on every route.

### WPMediaVerse's problem: 3 render paths, no shared wrapper

| Path | Entry point | Template(s) | Current top-level element |
|------|-------------|-------------|---------------------------|
| (1) Full-template via `template_redirect` | `TemplateLoader::load_media_templates()` → `serve_*()` `include $template` between `get_header()`/`get_footer()` | `templates/explore.php`, `templates/media-single.php`, `templates/profile-edit.php`, `templates/messages.php` | `explore.php:44` `<div class="mvs-explore-page">`; `media-single.php:117` `<div class="mvs-single-media mvs-page">`; `profile-edit.php:78` `<div class="mvs-profile-edit mvs-page" data-wp-interactive="mvs/profile-edit">`; `messages.php:16` `<div class="mvs-messages-page" data-wp-interactive="mvs/messaging">` |
| (2) CPT single via `single_template` | `TemplateLoader::load_single_template()` | `templates/album.php`, `templates/collection.php` | `album.php:22` `<div class="mvs-single-album">`; `collection.php:17` `<div class="mvs-single-collection">` |
| (3) Dashboard shortcode | `Shortcodes::render_dashboard()` + `templates/dashboard.php` | `templates/partials/dashboard-content.php` | `dashboard-content.php:110` `<div class="mvs-dashboard" data-wp-interactive="mvs/dashboard">` |

Because there is no single place that wraps the view, you must **not** hand-edit a region `<div>` into each of the 7 templates (that copy-pastes the region id 7 times and drifts). Instead, introduce **one shared region partial** that every path opens/closes around its view body.

### Recommended fix — one shared region partial, opened by each path

Create two tiny partials (new files — allowed, they are not under the read-only dirs you must not touch *content* of; create under `templates/partials/`):

- `templates/partials/region-open.php`:
  ```php
  <?php defined( 'ABSPATH' ) || exit; ?>
  <div id="mvs-app" data-wp-interactive="mvs/shared-ui" data-wp-on--click="actions.navigate">
    <div data-wp-interactive="mvs/shared-ui" data-wp-router-region="mvs/main">
  ```
- `templates/partials/region-close.php`:
  ```php
  <?php defined( 'ABSPATH' ) || exit; ?>
    </div><!-- [data-wp-router-region=mvs/main] -->
  </div><!-- #mvs-app -->
  ```

Then wrap each view body. The cleanest single-touch place is **inside the `mvs_before_content` / `mvs_after_content` actions** the templates already fire (`explore.php:16/528`, `media-single.php:65/661`, `album.php:17/441`, `collection.php:15/165`, `profile-edit.php:55/187`, `messages.php:13/52`, and the dashboard via its own wrapper). Today nothing hooks `mvs_before_content`/`mvs_after_content` for layout. Wire the region there from `Plugin::init()`:

```php
add_action( 'mvs_before_content', function () { include MVS_PLUGIN_DIR . 'templates/partials/region-open.php'; }, 1 );
add_action( 'mvs_after_content',  function () { include MVS_PLUGIN_DIR . 'templates/partials/region-close.php'; }, 99 );
```

That gives you ONE region wrapper, opened/closed identically on all of paths (1) and (2), with **no per-template edit** beyond confirming each view calls both actions (they all do). For path (3) the dashboard shortcode does not fire `mvs_before/after_content`, so emit the same partials around the `dashboard-content.php` include in `Shortcodes::render_dashboard()` (and in `templates/dashboard.php`).

**Region namespace note:** the wrapper uses `data-wp-interactive="mvs/shared-ui"` because that is the store that must own `actions.navigate` (it is already enqueued on every MVS page — `Plugin.php:1084` / `:150`). The inner per-view stores (`mvs/explore`, `mvs/dashboard`, `mvs/media-social`, `mvs/profile-edit`, `mvs/messaging`) keep their own `data-wp-interactive` on their own sub-elements inside the region; nesting is fine.

**Persistent chrome — keep OUTSIDE the region.** Already-correct: the FAB, upload modal, edit-media modal, lightbox, and toast all render in `wp_footer` via `render_shared_ui_frame()` (`Plugin.php:324`, partial `templates/partials/shared-ui-frame.php`) — they are siblings of `#mvs-app`, never swapped. **Must move out of the region body** (Standard rules 1 + 8) — these currently live *inside* the view body, so a swap would wipe them and they would need re-render:
- `explore.php:51-80` `.mvs-explore-header` and `:137-154` `.mvs-explore-search` + `:167` tag-cloud — header/search chrome. If you want it to survive nav, render it before the region opens (e.g. via `mvs_before_content` at a later priority, outside `region-open`). If you are fine with it swapping per-view (it is genuinely per-view on Explore vs profile), leave it inside — but then its enqueued script `mvs-explore-search` (`explore.php:155`) must become nav-aware (see Section 4).
- `media-single.php:118` and `album.php:63` back-link, and the profile header card `explore.php:106-133` are per-view and may stay inside the region.

---

## 3. Router registration

**Current state:** `@wordpress/interactivity-router` is **never** registered. Confirmed by grep across `assets/`, `src/`, `includes/` — 0 hits for `interactivity-router`, `add_client_navigation_support_to_script_module`, or `loadOnClientNavigation`.

The store module that must own navigation is `@mvs/shared-ui`, enqueued at:
- `includes/Core/Plugin.php:1084-1089` (MVS-page branch, ESM `src/blocks/shared-ui/view.js`):
  ```php
  wp_enqueue_script_module(
      '@mvs/shared-ui',
      MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
      array( array( 'id' => '@wordpress/interactivity' ) ),   // ← add router here
      MVS_VERSION
  );
  ```
- `includes/Core/Plugin.php:1133-1138` (off-MVS register-only branch, compiled `build/blocks/shared-ui/view.js`) — same dependency array needs the router too.

**Fix:** add the router as a **dynamic** dependency of `@mvs/shared-ui` so it loads once on first client-nav and is reused (mirrors Jetonomy `class-template-loader.php:375-388`):

```php
wp_enqueue_script_module(
    '@mvs/shared-ui',
    MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
    array(
        array( 'id' => '@wordpress/interactivity' ),
        array( 'id' => '@wordpress/interactivity-router', 'import' => 'dynamic' ),
    ),
    MVS_VERSION
);
```

Apply the identical change to the `build/` registration at `:1133`. Then, inside `src/blocks/shared-ui/view.js`, the `navigate` action `import('@wordpress/interactivity-router')` dynamically and calls `router.actions.navigate( href )` — exactly as Jetonomy's `view.js` `*navigate()` does (its lines ~688-764), including: bail on `defaultPrevented`, ignore `#`-anchors / modified clicks / cross-origin / `target=_blank`, the editor deny-list (Section 7), `event.preventDefault()`, dispatch `mvs:navigated`, then move focus to `[data-wp-router-region="mvs/main"]` and `window.scrollTo(0,0)`, with `window.location.href = href` as the never-strand fallback in `catch`.

> Note: `@mvs/shared-ui` is also force-registered for Pro feed shortcodes (`Plugin.php:1133`) and enqueued for the BP lightbox via `enqueue_shared_ui_for_feed()` (`Plugin.php:150`). Adding the dynamic router dep there is harmless (dynamic = not fetched until a nav fires), so a single edit covers every surface.

---

## 4. Nav-aware init

Every classic script below boots as an IIFE and binds handlers to elements found at parse time. With client-nav on, after the router swaps `[data-wp-router-region="mvs/main"]`, those elements are replaced and the handlers are gone. None of them listen for a re-init signal (no `mvs:navigated` exists yet). Fix pattern (Standard §4 canonical): make `init()` idempotent (guard with a `data-wired` attribute) and bind it to BOTH initial load AND `document.addEventListener('mvs:navigated', init)` — OR convert the behavior to a declarative `data-wp-on--*` store action so the router re-hydrates it for free.

### Region-content scripts that WILL break after a swap (must fix)

| File | What it wires | Why it dies | Fix |
|------|---------------|-------------|-----|
| `assets/js/frontend/load-more.js:15-16,40,95` | `gridContainer = querySelector('[data-mvs-grid-container]')`, binds click on the container + Load-More button | New grid container after swap has no listener; the captured `loadMoreBtn`/config is stale | Re-run its IIFE body in an idempotent `init()`; guard the container with `data-mvs-loadmore-wired`; bind `init` to load + `mvs:navigated`. Enqueued at `explore.php` via `mvs-load-more` handle |
| `assets/js/frontend/explore-search.js:17-19,36` | media/people search tabs + typeahead bound to `.mvs-search-mode-btn`, `#mvs-search-input` | All element-bound; swapped Explore view loses them | Same idempotent `init()` + `mvs:navigated`. (Enqueued `explore.php:155`) |
| `assets/js/frontend/profile-actions.js:20,69` | Follow / message buttons (`fbtn`, `mbtn`) bound by element | Element-bound on the profile feed view | Idempotent `init()` + `mvs:navigated`, OR fold Follow into the existing `mvs/media-social` `toggleFollow` declarative action (already used on media-single) |
| `assets/js/frontend/album-cover.js:15-17` | `querySelectorAll('.mvs-album-set-cover').forEach(addEventListener)` | Per-element bind inside album view | Convert to a declarative `mvs/media-social` (or new `mvs/album`) action `data-wp-on--click`, OR idempotent `init()` + `mvs:navigated` |
| `assets/js/frontend/album-upload.js:16-20` | album add-media dropzone, element-bound to ids | Inside album view body | Idempotent `init()` + `mvs:navigated` |
| `assets/js/frontend/collection-filter.js:18,31` | in-collection filter buttons, element-bound | Inside collection view body | Idempotent `init()` + `mvs:navigated` |
| `assets/js/frontend/bp-tab-upload.js:56-68` | BP profile-tab upload dropzone, element-bound to ids | BP tabs render server-side; lower risk but still element-bound | Idempotent `init()` + `mvs:navigated` if the BP tab ever lands inside the region |
| `assets/js/frontend/dismissible.js:34-43` | logged-out banner / profile-prompt close buttons, element-bound by id | `explore.php:41` + `dashboard-content.php:287` re-emit these per view | Idempotent `init()` + `mvs:navigated` |
| `assets/js/bp-activity-media.js:472-478` | `wrapActivityMediaGrids()` on `DOMContentLoaded` (the *activity composer* preview); plus the lightbox driver | Composer/preview binding via `DOMContentLoaded` only | This file runs on BP activity streams which are largely outside the MVS region. **Lower priority** — but if BP activity ever renders inside `mvs/main`, gate `wrapActivityMediaGrids` to also run on `mvs:navigated`. Its lightbox click handlers (`:1081`, `:1159`, etc.) are already **document-delegated** and survive — leave them. |
| `assets/js/frontend/messages-scroll.js:22-26` | `scrollChatIntoView()` on `DOMContentLoaded` only | Runs once; after a client-nav into `/messages/` the scroll-into-view never fires | Either fold into the `mvs/messaging` store `onInit` (declarative, hydrates on swap) or bind to `mvs:navigated`. Enqueued `messages.php:48` |

### Document-delegated scripts — SAFE, do not touch

These bind to `document` and match via `closest()`, so they survive swaps with no re-init:
- `assets/js/frontend/bp-actions.js:89` (`document.addEventListener('click', …)` + `closest('.mvs-media-edit-btn')` etc.).
- `assets/js/bp-activity-media.js` lightbox driver (`:1081`, `:1159`, `:1179`, `:1208`, `:1231`, `:1270`, `:1286`, `:1300`).

### Out of scope — admin-only

- `assets/js/settings-nav.js:138-142` uses `DOMContentLoaded`, but it runs on the **wp-admin settings page**, which never uses the iAPI front-end router. **No change needed** (per the team's "admin screens desktop+iPad, no client-nav" rule). The standard's grep flags it; document it as a known false-positive.

---

## 5. Inline scripts

There is exactly **one** `wp_add_inline_script` on the frontend:

- `includes/Core/Plugin.php:1064-1067` — attaches to the `mvs-lucide` handle:
  ```php
  wp_add_inline_script(
      'mvs-lucide',
      'document.addEventListener("DOMContentLoaded",function(){if(window.lucide&&typeof window.lucide.createIcons==="function"){window.lucide.createIcons();}});'
  );
  ```

**Verdict: drives region behavior — MUST become nav-aware (do not leave as-is).** This is the icon hydrator: it converts every `<i data-lucide="…">` placeholder (used throughout `media-single.php`, `shared-ui-frame.php` lightbox action bar, dashboard) into an SVG. It runs **once** on `DOMContentLoaded`. After a client-nav swap, the freshly-injected `<i data-lucide>` placeholders in the new view are never converted → broken/blank icons. This is precisely the Standard §5/§7 anti-pattern ("inline scripts in a swapped fragment do not execute").

**Fix:** also call `window.lucide.createIcons()` on `mvs:navigated`. Since `createIcons()` is idempotent (re-running it is safe), the simplest fix is to extend the inline payload:
```js
document.addEventListener("DOMContentLoaded", reicon);
document.addEventListener("mvs:navigated", reicon);
function reicon(){ if(window.lucide&&window.lucide.createIcons){window.lucide.createIcons();} }
```
(Or, cleaner, move it into the shared-ui store's `navigate` action after the router swap completes.) The other inline outputs in the codebase are admin (`admin/icons.js` enqueues, toast) and the messaging config localize (`print_messaging_config`, `Plugin.php:1653`) which is a **harmless data localize**, not behavior — leave it.

---

## 6. Centralized fetch

**No shared REST client exists.** Grep for `mvsRest` / `restFetch` / `window.mvs*Rest` → **0 hits**. Every frontend mutation hand-rolls `fetch()` with `headers: { 'X-WP-Nonce': nonce }` and no 403/`rest_cookie_invalid_nonce` refresh. The 10 offenders:

| File | Region behavior? | Notes |
|------|------------------|-------|
| `assets/js/messaging.js:25,776,883` | ✅ yes (DM send + media upload) | iAPI store `mvs/messaging`; should route through shared client |
| `assets/js/profile-edit.js:74,144,186` | ✅ yes (profile save + avatar up/del) | iAPI store `mvs/profile-edit` |
| `assets/js/bp-activity-media.js:239,574,788,970,…` + apiGet/apiPost/apiDelete `:612-634` | ⚠️ mixed | BP activity composer + lightbox; mostly outside region but same nonce risk |
| `assets/js/frontend/load-more.js` | ✅ yes (feed pagination) | reads `data-nonce` off the button |
| `assets/js/frontend/explore-search.js` | ✅ yes (user search) | |
| `assets/js/frontend/bp-actions.js` | ✅ yes (edit/delete media+album) | document-delegated, but still raw fetch |
| `assets/js/frontend/profile-actions.js` | ✅ yes (follow/message) | |
| `assets/js/frontend/album-cover.js` | ✅ yes (set cover) | |
| `assets/js/frontend/bp-tab-upload.js` | ✅ yes (BP tab upload) | |
| `assets/js/frontend/album-upload.js` | ✅ yes (album add-media) | |

Note the iAPI store modules (`src/blocks/*/view.js` — explore-view, dashboard-view, media-social, shared-ui) also do their own fetches; they were not enumerated line-by-line here but fall under the same rule.

**Fix (Standard rule 6):** build one `assets/js/mvs-rest.js` exposing `window.mvsRest.restFetch(path, opts)` that returns `{ ok, status, data }`, injects `X-WP-Nonce`, and on a 403 `rest_cookie_invalid_nonce` re-fetches a fresh nonce (from a `rest-nonce` endpoint or `wpApiSettings`) and retries once. Register it as a dependency of every frontend script + as a hard dep of the store modules, exactly as Jetonomy does with `window.jetonomyRest.restFetch` (`class-template-loader.php:797-821` `enqueue_rest_client()`, consumed throughout `view.js`). Then convert the 10 files' raw `fetch(` calls to `mvsRest.restFetch`. Raw `fetch` stays allowed only inside `mvs-rest.js` itself and inside `sendBeacon`/orphan-cleanup paths (`bp-activity-media.js:277` uses `navigator.sendBeacon`, which is fine).

This is a larger refactor; sequence it after the router lands (Section 9) so nonce-refresh covers the new long-lived single-page sessions client-nav creates.

---

## 7. Deny-list (routes that must full-load)

With client-nav on, the default is "everything client-navs." Keep a **minimal deny-list** in the `navigate` action for rich-media composer/editor routes whose scripts bind on load and are not worth re-initing:

- **Profile edit** — `/…profile-edit/` (path 1, `serve_profile_edit()` → `templates/profile-edit.php`). Standalone avatar-upload + form composer.
- **Messages** — `/messages/` (path 1, `templates/messages.php`). The `mvs/messaging` store + `messages-scroll` + media-upload composer; full-load gives a clean chat init (and matches Jetonomy treating its messaging/editor routes as full-load).
- **Album single when owner** — `templates/album.php` owner branch enqueues `mvs-album-upload` / `mvs-album-cover` / `mvs-album-playlist` (`album.php:205,306,364`), all element-bound composers. Deny-list the **owner edit** experience (or make those scripts nav-aware and drop them from the deny-list — your call).
- **Dashboard** — `templates/partials/dashboard-content.php`. It is a shortcode page with the full upload/album/collection composer; simplest to full-load (path 3 isn't even inside the standard region today).

Everything else — **Explore feed, profile feed (explore.php profile branch), single media view (media-single.php), collection view, tag/category archives** — should client-nav. These are read-mostly views whose interactivity is already declarative (`mvs/media-social`, `mvs/explore`) and re-hydrates on swap.

Implement the deny-list as a path-segment check in the `navigate` action (mirror Jetonomy `view.js:732` `const editorRoute = …; if (editorRoute) return;`), e.g. match `messages`, `profile-edit`, and the dashboard page slug → `return` (let the browser do a normal navigation). Prefer a deny-list over an allow-list so new read routes are fast by default (Standard rule 7).

---

## 8. Conservative rollout flag

Mirror BuddyNext's `buddynext_client_nav_enabled`. Add `wpmediaverse_client_nav_enabled` (default `false` during rollout, flip to `true` once verified):

- **Gate the router registration** (Section 3): only add the `@wordpress/interactivity-router` dynamic dep when `apply_filters( 'wpmediaverse_client_nav_enabled', false )` is true (`includes/Core/Plugin.php:1084` and `:1133`).
- **Gate the region wrapper emission** (Section 2): the `mvs_before_content`/`mvs_after_content` region partials (and the dashboard wrapper) only open the `data-wp-router-region` element when the filter is true. When off, render the view exactly as today (the inner `data-wp-interactive` stores still work; only the router region + delegated `actions.navigate` are suppressed), so full-load behavior is byte-identical and risk-free.
- **Gate the `navigate` action** client-side too: read the flag from `wp_interactivity_state('mvs/shared-ui', ['clientNav' => …])` and bail early in `navigate` if false — belt-and-suspenders so a stale cached `view.js` can't start swapping on a site that turned the flag off.

A site (or QA) flips it with one line: `add_filter( 'wpmediaverse_client_nav_enabled', '__return_true' );`.

---

## 9. Prioritized fix order

Do these top-down. Effort: **S** ≈ <1h, **M** ≈ half-day, **L** ≈ 1–2 days. Verify each per Standard §6 (full-load a different page → click in → exercise → confirm parity) before moving on.

1. **(S) Add the rollout filter** `wpmediaverse_client_nav_enabled` (default false). Everything below is gated on it, so nothing ships hot. — *Section 8.*
2. **(S) Register the router** as a dynamic dep of `@mvs/shared-ui` at `Plugin.php:1084` and `:1133`, gated on the flag. — *Section 3.*
3. **(M) Region wrapper, once.** Add `region-open.php` / `region-close.php` partials and hook them on `mvs_before_content`/`mvs_after_content` (+ dashboard wrapper), gated on the flag. Confirm all 7 view bodies sit inside one `data-wp-router-region="mvs/main"`. — *Section 2.*
4. **(M) `navigate` action in `src/blocks/shared-ui/view.js`.** Delegated link handler: deny-list (Section 7), dynamic-import router, `router.actions.navigate`, dispatch `mvs:navigated`, focus region + scroll-top, full-load fallback in `catch`. — *Sections 3 + 7.*
5. **(S) Lucide re-icon on `mvs:navigated`.** Extend the inline at `Plugin.php:1064` (or move into the navigate action) so icons hydrate after every swap. — *Section 5.* (Without this, the very first swap looks broken.)
6. **(M) Make the region's classic scripts nav-aware.** load-more.js, explore-search.js, profile-actions.js, album-cover.js, album-upload.js, collection-filter.js, dismissible.js, messages-scroll.js → idempotent `init()` + `mvs:navigated` (or declarative). — *Section 4.*
7. **(L) Centralized REST client.** Build `assets/js/mvs-rest.js` (`window.mvsRest.restFetch`, nonce-refresh on 403); convert the 10 raw-`fetch` files + the store modules to use it. — *Section 6.*
8. **(M) Verify every interactive surface via the client-nav path** (Standard §6) on light + dark, 390px + desktop: Explore feed, profile feed, single media (reactions/comments/favorite/edit), collection, tag/category, lightbox, FAB upload. Confirm deny-listed routes (messages, profile-edit, dashboard, album-owner) still full-load cleanly. — *Section 6 + 7.*
9. **(S) Flip the flag default to `true`**, re-run the §6 sweep once more, update CLAUDE.md with the one-line standard pointer.

---

## Maintainer checklist (tick as you go)

- [ ] `wpmediaverse_client_nav_enabled` filter added, default false; gates router + region + navigate action.
- [ ] `@wordpress/interactivity-router` added as a **dynamic** dep of `@mvs/shared-ui` at `Plugin.php:1084` **and** `:1133`.
- [ ] Exactly **one** `data-wp-router-region="mvs/main"` element wraps the view body on all 7 templates + dashboard (`grep -rn data-wp-router-region templates/` → matches only the shared partial).
- [ ] Region element also carries `data-wp-interactive`; the outer `#mvs-app` wrapper carries `data-wp-on--click="actions.navigate"`.
- [ ] Persistent chrome (FAB/modals/lightbox/toast in `wp_footer`, and any header you choose to persist) renders **outside** the region.
- [ ] `navigate` action: deny-list, dynamic router import, dispatches `mvs:navigated`, focuses region, scrolls top, `window.location.href` fallback in `catch`.
- [ ] Lucide `createIcons()` re-runs on `mvs:navigated` (no blank icons after a swap).
- [ ] Every region classic script (load-more, explore-search, profile-actions, album-cover, album-upload, collection-filter, dismissible, messages-scroll) is idempotent + listens for `mvs:navigated` (or is now declarative).
- [ ] No `DOMContentLoaded`-only handler targets region content (admin `settings-nav.js` exempt + documented).
- [ ] `window.mvsRest.restFetch` exists; all 10 raw-`fetch` frontend files + store modules route through it; raw `fetch` remains only inside `mvs-rest.js` + `sendBeacon`.
- [ ] Deny-list (not allow-list) governs full-load routes; limited to messages / profile-edit / dashboard / album-owner edit; documented in the navigate action.
- [ ] Verified per Standard §6 (client-nav path, not just full load) on light + dark, 390px + desktop, for every interactive surface.
- [ ] Flag default flipped to true; CLAUDE.md points to `docs/standards/frontend-interactivity.md`.

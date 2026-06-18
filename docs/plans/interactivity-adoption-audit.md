# WPMediaVerse — Frontend Interactivity Adoption: Per-Surface Audit

Companion to `interactivity-standard-adoption.md`. This is the detailed per-file
audit promised in that brief, covering **every** frontend surface (Free + Pro)
against the standard in `docs/standards/frontend-interactivity.md`. Produced by a
6-way parallel audit on 2026-06-19.

## Headline state

- **Zero `data-wp-router-region` anywhere** (Free or Pro). Client-side nav is not
  started — the brief's plan has not shipped.
- **No `actions.navigate`, no `@wordpress/interactivity-router` registration, no
  store in the plain `mvs` namespace.** Every region uses a sub-namespace
  (`mvs/shared-ui`, `mvs/dashboard`, `mvs-pro/battles`, …).
- **No shared REST client (`window.mvsRest.restFetch`) anywhere.** ~100+ raw
  `fetch()` sites across Free stores, Free classic JS, messaging, and all Pro
  stores, each hand-rolling `X-WP-Nonce` with no 403/nonce-refresh (Rule 6).
- `@mvs/shared-ui` is registered/enqueued in **5 places** with inconsistent dep
  arrays across **two source files** (`src/` ESM vs `build/` IIFE) — drift risk.
- The lucide `MutationObserver` (Plugin.php:1782) already re-hydrates icons after
  any DOM swap — so swapped-in `<i data-lucide>` icons are NOT a gap.

## Route classification (the deny-list / client-nav decision)

| Route | Template | Class | Why |
|---|---|---|---|
| `/media/explore-media`, `/media/` archive, tax/album archives | explore.php | **CLIENT-NAV** | pure listing + tag cloud + search |
| `/media/{slug}/` single media | media-single.php | **CLIENT-NAV** | detail; inline-edit is declarative |
| `/media/collection/{slug}` | collection.php | **CLIENT-NAV** | read-only grid + client filter |
| `/media/@{user}/` profile view | user-profile.php (Pro) / explore fallback | **CLIENT-NAV** | static profile |
| explore re-skin (Instagram layout) | instagram-feed.php (Pro) | **CLIENT-NAV** | static public feed |
| `/media/edit-profile/` | profile-edit.php | **DENY-LIST** | avatar upload composer |
| `/media/album/{slug}` | album.php | **DENY-LIST** | owner upload/dropzone/playlist/cover composer |
| dashboard (shortcode page) | dashboard-content.php | **DENY-LIST** | primary upload composer |
| `/messages/` | messages.php | **DENY-LIST** | typeahead, auto-scroll, polling, upload, stale-nonce |
| `mvs_page_upload` | — | **DENY-LIST** | upload composer |
| `/compete/` | compete-hub.php (Pro) | **DENY-LIST** | live countdown timer + aggregated live state |
| `/media/battles(/{id})?` | battles.php (Pro) | **DENY-LIST** | live voting / matchup state |
| `/media/challenges(/{id})?` | challenges.php (Pro) | **DENY-LIST** | voting / entry submission |
| `/media/tournaments(/{id})?` | tournaments.php (Pro) | **DENY-LIST** | live bracket state |

Deny-list (not allow-list): everything else under `/media/` client-navs by default.

## Region wrapper injection points (Free templates)

Each template owns its own `get_header()`/`get_footer()`; the region partial must
be emitted **inside** each template (not around the include in TemplateLoader).
Region id **must be byte-identical** `mvs/main` on `<div data-wp-interactive="mvs"
data-wp-router-region="mvs/main">`. Wrap the success-path content only — NOT the
early 404/guard branches.

| Template | get_header | open region after | close region before | branches |
|---|---|---|---|---|
| explore.php | 14 | line 44 (`.mvs-explore-page`) | 519 | 1 (no early-return) |
| media-single.php | 63 | ~66 | ~662 | 3 (skip 404 @15, privacy @52) |
| profile-edit.php | 53 | ~78 | ~188 | 2 (skip logged-out @16) |
| album.php | 15 | ~16 | both 404-close @37 AND 442 | 2 |
| collection.php | 13 | ~14 | ~166 | 1 |
| dashboard-content.php | (caller) | wrap include @ Shortcodes.php:468 (in ob buffer) | — | partial |

Persistent chrome (FAB, upload modal, lightbox, toast) already renders in
`wp_footer` via `shared-ui-frame.php` → already outside the region (Rule 2/8 ✓).
**Caveat:** media-single (640-659), album (420-439), dashboard (906-926) ALSO
render `mvs/shared-ui` toast/confirm modals *inside* content — consolidate to the
footer frame or use delegated dispatch (per-route duplication gotcha).

## JS — navigate action + nav-aware init

**Navigate action home:** add a thin second store `store('mvs', { actions:{
*navigate } })` inside `src/blocks/shared-ui/view.js` (a module may declare
multiple stores). This honours the brief's `mvs` namespace + `mvs/main` id while
shipping with the already-global `@mvs/shared-ui` module — no new enqueue. The
action: link-guard → deny-list check → `yield import('@wordpress/interactivity-
router')` → `router.actions.navigate(href)` → dispatch `mvs:navigated` →
focus/scroll the region → no-op when `context.clientNav` is false. **Requires
`npm run build`.** Router added as a `{id, import:'dynamic'}` dep in PHP.

**Classic scripts on client-nav routes that DIE on swap (must add idempotent
guard + `mvs:navigated` listener):**

| Script | Route | Fix | Impact |
|---|---|---|---|
| load-more.js | all grids | nav-aware + fetch | **highest** — Load More + delegated lightbox both die |
| profile-actions.js | profile view | nav-aware + fetch | Follow/Message dead |
| explore-search.js | explore | nav-aware + fetch | search tabs + typeahead dead |
| collection-filter.js | collection | nav-aware | filter dead |
| dismissible.js | explore/dashboard | nav-aware (or relocate callout) | dismiss dead |

**No nav-work needed:** `settings-nav.js` (admin), `mvs-confirm.js` /
`card-builders.js` (body-level libs), `bp-*` (BuddyPress-owned screens, not the
mvs region; document-delegated), `profile-edit.js` (declarative store).
`album-upload/cover/playlist.js` run only on the deny-listed album route → safe.
`messages-scroll.js` runs only on deny-listed `/messages/` → safe.

**Rule 5:** route the Plugin.php:1054-1067 MVS-page branch through
`register_lucide_script()` instead of the inline `DOMContentLoaded` one-liner
(the MutationObserver helper then re-hydrates icons after swaps).

## Messaging slide-out (independent, ships on every page)

`callbacks.onInit` (messaging.js:1335-1379) is **not idempotent** — re-running
double-binds `mvs-open-conversation` and stacks a second unread `setInterval`;
`startPolling` has no teardown. Because the slide-out chat-panel renders in
`wp_footer` on EVERY page including client-nav'd ones, this must be fixed
regardless of the `/messages/` deny-list: guard the listener + timer, wire
`stopPolling()` to navigate-away.

## Pro specifics

- **Competition `*-body.php` enqueue their store module from inside the swapped
  region** (Rule 2) — move enqueues to the PHP loader's `wp_enqueue_scripts`.
- compete-hub-store.js:150 `setInterval` (`timerInterval`) is never cleared —
  leak on swap (mitigated by deny-listing `/compete/`).
- instagram-feed.php + user-profile.php **must reuse Free's `mvs/main` region +
  `mvs` namespace byte-identically** (they already depend on Free `mvs/messaging`
  + `mvs/profile-edit` stores and Free REST). Their IntersectionObservers
  (`observeSentinel`/`observeVisibility`) are not disconnected on swap — observer
  leak; disconnect on `mvs:navigated`.
- compete-banner.js binds element-level on a banner at module eval with no
  `mvs:navigated` — place in persistent chrome or make nav-aware.
- collection-picker.js is already nav-safe (document-delegated, capture-phase).

## Rule 6 — shared REST client (cross-cutting, ~100+ sites)

No `window.mvsRest.restFetch` exists. Independent of navigation but part of the
standard. Build one shared client (nonce + 403-refresh) and migrate: Free stores
(~55), Free classic JS (~12), messaging (3), Pro stores (~40). Largest single
item; recommended as its own wave so the client-nav change stays verifiable.

## Recommended sequencing

- **Wave 1 (client-nav adoption — one verifiable sitting):** region partials +
  wrap all view templates; `mvs` navigate store + router dep + enqueue
  consolidation; feature flag; nav-aware init for the 5 client-nav scripts; lucide
  Rule-5 fix; within-Media + Pro deny-list; messaging slide-out idempotency;
  Instagram/user-profile region adoption + observer teardown; build; browser-verify
  every client-nav surface per standard Section 6.
- **Wave 2 (Rule 6):** shared REST client + fetch migration across Free + Pro.

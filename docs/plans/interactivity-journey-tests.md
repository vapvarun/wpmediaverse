# WPMediaVerse Client-Nav — Browser Journey Test Plan

> **STATUS: TEST PLAN, not a record of results.** Companion to
> `interactivity-standard-adoption.md` (1.7.1). Nothing here asserts current
> behaviour; the executable regression suite is `audit/journeys/`.

Executed in Task 7 (and re-run after any fix). Method per the standard
`docs/standards/frontend-interactivity.md` Section 6: for every interactive surface,
test the CLIENT-SIDE path, not just full load — full-load a different page, click a
link to navigate (client-side swap), exercise the control, confirm it behaves
identically to a full load. Walk each as a USER JOURNEY end-to-end (flow-model), not
isolated component pokes.

## Tooling & setup
- Playwright MCP tools directly (`browser_navigate`, `browser_click`,
  `browser_snapshot`, `browser_take_screenshot`, `browser_evaluate`) — NOT scripts.
- Auto-login: append `?autologin=1` (admin) to the first navigation.
- **Swap sentinel (proves no full reload):** before a client-nav click, run
  `browser_evaluate`: `window.__mvsNav = performance.now(); document.documentElement.dataset.mvsProbe='1'`.
  After the click, assert `document.documentElement.dataset.mvsProbe === '1'` is STILL
  set (a full reload wipes it) AND that a `mvs:navigated` event fired (set a listener
  that increments a counter). A surviving probe + incremented counter = true client swap.
- Viewports: desktop (1280) AND mobile 390px for every member-facing surface.
- Theme: run the host theme's dark-mode toggle and confirm tokens follow (BN dark
  trigger) on at least the explore + single-media surfaces.
- Screenshots → `~/Documents/work-artifacts/screenshots/2026-06/` (never cwd).

## A. CLIENT-NAV journeys (must swap, not reload; controls must survive)

1. **Explore → single media → back.** Full-load `/media/`. Click a media tile's
   permalink → asserts client swap to single-media. Confirm the single-media page's
   reactions/favorite/comment (declarative `mvs/media-social`) work. Browser-back →
   explore restored, tiles still clickable.
2. **Explore → Load More → lightbox on an appended tile.** (HIGHEST RISK — load-more.js
   nav-awareness.) Client-nav into explore from another page first, THEN click Load
   More, THEN open the lightbox on a freshly-appended tile. Lightbox must open, nav
   arrows + reactions + comment must work. This is the exact "dies after swap" case.
3. **Explore search after swap.** Client-nav into explore, then switch People/Media
   search tabs and type in the search box → results appear (explore-search.js
   nav-aware).
4. **Explore → user profile → Follow / Message.** Client-nav to a user profile view;
   click Follow then Message (profile-actions.js nav-aware). Toggle persists.
5. **Collection → filter after swap.** Client-nav to a collection; type in the
   collection filter → grid filters (collection-filter.js nav-aware).
6. **Dismissible callouts after swap.** On explore/dashboard reached via client-nav,
   dismiss the logged-out/profile-prompt callout → it closes (dismissible.js nav-aware).
7. **Lucide icons after swap.** After any client swap, confirm `<i data-lucide>` icons
   in the swapped region rendered to SVG (MutationObserver re-hydration).
8. **Pro: Instagram feed infinite scroll after swap.** Client-nav to the IG feed;
   scroll to trigger the IntersectionObserver sentinel → next page loads; confirm no
   duplicate/leaked observers after a second swap (scroll still triggers exactly once).
9. **Pro: user profile after swap.** Client-nav to a Pro user profile; owner inline-edit
   (mvs/profile-edit) works.

## B. PERSISTENT CHROME across swaps (must survive every client-nav)
10. **FAB + upload modal.** On a client-nav'd page, the floating upload button is
    present; open it → modal works, tabs switch, dropzone responds. Persists after a
    second swap (it lives in the footer frame, outside the region).
11. **Messaging slide-out (no leak).** Open the chat slide-out, client-nav between 2-3
    explore/single-media pages, confirm: unread poll does NOT stack (check only one
    interval via a probe), the `mvs-open-conversation` listener isn't double-bound
    (open a conversation, confirm it opens once), and sending still works.
12. **Toast/confirm.** Trigger a toast (e.g. favorite) on a client-nav'd page → appears
    once (footer-frame copy, not a per-template duplicate).

## C. DENY-LIST journeys (must FULL-load, not swap)
For each, click a link TO the route from a client-nav surface and assert the probe was
WIPED (full reload) and the route renders correctly:
13. Upload page · 14. `/media/edit-profile/` · 15. Album (owner composer: dropzone,
    playlist, set-cover all work on full load) · 16. Dashboard (upload composer) ·
17. `/messages/` (typeahead + auto-scroll + send) · 18. Pro `/compete/` (live timer) ·
19. Pro `/media/battles/`, `/media/challenges/`, `/media/tournaments/` (voting/bracket).

## D. Regression / safety
20. **Feature flag OFF.** Set `add_filter('wpmediaverse_client_nav_enabled','__return_false')`
    (mu-plugin or snippet); confirm EVERY link full-loads (probe wiped everywhere) and
    every surface still works — proves the region wrapper + nav-aware init are inert-safe
    when disabled.
21. **JS-off / error fallback.** Force the router import to throw (or block the module);
    confirm links still navigate via native `<a href>` (never stranded).
22. **BN → Media boundary.** From the BuddyNext Explore Media page, click into Media →
    full-loads (BN deny-list already handles this); then within Media, nav swaps.
23. **Pro inactive.** Deactivate Pro; confirm Free surfaces still client-nav, denyPaths
    has only Free entries, no fatals.

## E. Static gates (run alongside)
- `node --check` on every touched classic JS; `npm run build` clean.
- `php -l` + phpcs on every touched PHP.
- Compliance greps from the standard: exactly one `data-wp-router-region="mvs/main"`
  per client-nav page; no remaining DOMContentLoaded-only handler on region content
  without a `mvs:navigated` listener; no raw frontend `fetch(` outside mvs-rest.

## Pass criteria
A surface PASSES only when its client-side path behaves identically to full load
(Section 6). A surface that works on full load but is dead/blank/leaking after a
client-side navigation is FAILING even if code review looked clean. Log every failure
with the journey number + screenshot; fix, then re-walk that journey.

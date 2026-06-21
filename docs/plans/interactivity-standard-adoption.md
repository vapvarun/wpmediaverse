# WPMediaVerse 1.7.1 — Adopt the Frontend Interactivity & Client-Side Nav Standard

**Goal:** make WPMediaVerse's frontend views client-navigate uniformly (like
Jetonomy, the reference implementation), so the BuddyNext suite has one UX feel.
Today WPMediaVerse uses the Interactivity API (namespace `mvs/*`) but has **no
router region, no `@wordpress/interactivity-router`, and no nav-aware init** — so
its internal nav is classic full-loads.

**Spec (normative, read first):**
`/Users/vapvarun/dev/repos/jetonomy-pro/docs/standards/frontend-interactivity.md`
**Reference implementation to copy:** Jetonomy (`/Users/vapvarun/dev/repos/jetonomy`)
— study `includes/class-template-loader.php` (region wrapper + router registration)
and its `assets/js/view.js` (nav-aware `init()` + `jetonomy:navigated`).

**Repo / branch:** `/Users/vapvarun/dev/repos/wpmediaverse` on `1.7.1`
(symlinked live at the buddynext-dev site — edits are live).

---

## Current state (audited)

- Interactivity API in use, namespace `mvs/*`. Stores: `mvs/shared-ui`,
  `mvs/dashboard`, `mvs/explore`, `mvs/messaging`, `mvs/profile-edit`,
  `mvs/media-social`, `mvs/media-player`.
- Common shell: `templates/partials/shared-ui-frame.php` — `.mvs-app-shell`,
  `data-wp-interactive="mvs/shared-ui"`, carries the persistent **FAB + upload
  modal**.
- Script modules registered in `includes/Core/Plugin.php` via
  `wp_enqueue_script_module` / `wp_register_script_module` with the
  `@wordpress/interactivity` dependency.
- **3 rendering paths (key constraint — there is no single content wrapper):**
  1. Full-template includes (`explore.php`, `media-single.php`,
     `profile-edit.php`) via `template_redirect`, each calling
     `get_header()` / `get_footer()` and rendering the whole page.
  2. CPT/page templates (`album`, `collection`) via the `single_template` filter.
  3. Dashboard shortcode on a regular WP page.
- `grep -rn "data-wp-router-region" templates/` → none. No client-nav support.
  3 `DOMContentLoaded` scripts in `assets/js/`, 0 handling `mvs:navigated`.

---

## Definition of done (the standard's rules)

1. **One router region per layout.** Because each view template renders
   independently, decorate the **per-view content container** in EACH view
   (`explore.php`, `media-single.php`, `profile-edit.php`, `album`, `collection`,
   dashboard) with the SAME element carrying both `data-wp-interactive="mvs"` AND
   `data-wp-router-region="mvs/main"`. The id MUST be byte-identical across every
   route or the swap fails. Prefer introducing one shared partial
   (e.g. `templates/partials/router-region-open.php` / `-close.php`, or extend
   `shared-ui-frame.php` into a real frame the views call) so the region markup
   lives in ONE place rather than copy-pasted into 6 templates.
2. **Persistent chrome outside the region.** The FAB, upload modal, and any
   header/nav render BEFORE the region opens so they survive swaps and never need
   re-wiring. (Today they're inside `.mvs-app-shell` — keep them in the shell but
   OUTSIDE the `mvs/main` region.)
3. **Register the router once.** Make `@wordpress/interactivity-router` a dynamic
   dependency of the `mvs/shared-ui` store module (or call
   `wp_interactivity()->add_client_navigation_support_to_script_module()`), the
   way Jetonomy does in `class-template-loader.php`. No per-route scripts for
   region content.
4. **Nav-aware init.** Every classic/imperative script targeting region content
   (the 3 `DOMContentLoaded` ones) must be idempotent (guard with a
   `:not([data-wired])`-style check) AND bind `init()` to BOTH initial load AND a
   custom `mvs:navigated` event the navigate action dispatches after each swap.
   Prefer converting to declarative `data-wp-on--*` store actions where cheap.
5. **No inline scripts** (`wp_add_inline_script` / inline `<script>`) driving
   region behavior — they don't run in a swapped fragment. Move into the store
   module's nav-aware `init()`.
6. **Centralized fetch** through one shared REST client with nonce-refresh
   (`window.mvsRest.restFetch` or equivalent); no scattered raw `fetch()`.
7. **Minimal deny-list (not allow-list)** for routes that must full-load — the
   rich media **upload/edit composer** routes. Everything else client-navs.
8. **Sync the standard:** copy it to `wpmediaverse/docs/standards/frontend-interactivity.md`
   and add a one-line pointer in the plugin `CLAUDE.md`.
9. **Conservative rollout:** gate client-nav behind a filterable flag (mirror
   BuddyNext's `buddynext_client_nav_enabled` → e.g. `wpmediaverse_client_nav_enabled`)
   so it can be toggled; the region wrapper + nav-aware init must be correct
   regardless of the flag.

---

## Compliance greps (from the standard)
```
grep -rn "data-wp-router-region" templates/            # expect exactly one region id (mvs/main), reused
grep -rn "wp_add_inline_script" includes/              # none for frontend region behavior
grep -rn "DOMContentLoaded" assets/js/ | grep -v min   # each must ALSO handle mvs:navigated
grep -rn "fetch(" assets/js/ | grep -v "restFetch\|min" # raw fetch = smell
```

## Verify (live plugin — curl as admin)
```
COOKIE=$(/Users/vapvarun/Library/Application\ Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php \
  -d opcache.enable=0 /opt/homebrew/bin/wp eval \
  'echo "wordpress_logged_in_".COOKIEHASH."=".wp_generate_auth_cookie(1,time()+3600,"logged_in");' \
  --path="/Users/vapvarun/Local Sites/buddynext-dev/app/public" 2>/dev/null | grep wordpress_logged_in)
for u in explore-media/ my-media/ upload-media/ ; do
  curl -s -H "Cookie: $COOKIE" "http://buddynext-dev.local/$u" | grep -iE "fatal error|parse error|undefined |wp_error|notice:|warning:" | head
done
```
Confirm in the HTML: exactly ONE `data-wp-router-region="mvs/main"` per page, the
FAB/upload-modal rendered OUTSIDE it, the interactivity-router module present.
Then Playwright-verify: from the Explore Media page, click an internal Media link
and confirm it client-swaps (no full reload) and the directives/FAB still work.
Lint every touched PHP (`php -l`) + JS (`node --check`); run the plugin's phpcs.

---

## Related (suite-wide, separate)
- **BuddyNext side is DONE** (committed on `master`): partner routes
  (Explore Media page + `/media/` base; Jetonomy community base) full-load via a
  config-driven deny-list in `PageRouter::wpmediaverse_deny_paths()` /
  `jetonomy_deny_paths()`. So BN→Media is correct today; this brief makes nav
  *within* Media smooth.
- **Jetonomy `1.5.0-dev`:** largely compliant; tidy the 2 of 5 `DOMContentLoaded`
  scripts (in `assets/js/`) that don't also listen for `jetonomy:navigated`.
- **Stretch (architectural):** truly seamless cross-plugin swap (no reload at the
  BN↔Media boundary) needs a SHARED suite-wide region id; today each plugin owns
  its own (`buddynext/main`, `jetonomy/main`, `mvs/main`).

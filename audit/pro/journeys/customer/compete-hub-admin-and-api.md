---
journey: compete-hub-admin-and-api
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member, anonymous]
covers: [MV-CMP-002, MV-CMP-006, MV-CMP-008, MV-CMP-009, MV-CMP-010, compete-hub-block, dashboard-tab-switching, members-only-gate]
prerequisites:
  - "Both plugins active; mvs_competitions_enabled = 1"
  - "Auto-login mu-plugin available"
estimated_runtime_minutes: 8
---

# The hub isn't nav-injected, its summary endpoint busts cache on writes, the block matches the page, and private-community gating covers all four Compete URLs

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member>`

## Steps

### 1. Compete is never auto-injected into navigation (Coding Rule 17)
- **Action**: enable competitions + battles on a fresh site; do NOT touch Appearance → Menus or the My Media dashboard rail; browse as a member looking for a way into Compete.
- **Expect**: no automatic "Compete" link anywhere — not the primary nav, not the dashboard rail (removed in 2.4.2 after causing a blank-panel bug, see step 3). The only ways in are a manually-added menu item, a direct URL, the `mvs/pro-compete-hub` block, or the `mvs_pro_inject_compete_nav` filter returning true (default false).
- **On fail**: any `wp_nav_menu_items` hook or dashboard-rail registration re-added for Compete — Coding Rule 17 forbids this.

### 2. Summary endpoint busts cache immediately on a write
- **Action**: `curl $SITE_URL/wp-json/mvs-pro/v1/competitions/active-summary` logged out; resolve a battle (or finalize a challenge); re-curl the same endpoint within the cache TTL.
- **Expect**: the response reflects the write in the SAME request cycle — a cache-version bump, not a wait-out-the-TTL delay. JSON has `active_challenge` (object or null), `open_tournaments` (array), `recent_battles` (array, up to 5).
- **On fail**: `includes/REST/CompeteSummaryController.php` cache-key/version bump on the relevant transition hooks.

### 3. Dashboard tab-switching never leaks or blanks a panel
- **Action**: on `/my-media/`, switch through every dashboard tab in sequence including any Pro-registered section via `mvs_dashboard_sections` (e.g. Documents); rapid double-click between two tabs; use browser back/forward mid-load.
- **Expect**: no tab ever renders blank, no other tab's content leaks through. A section whose capability callback denies the current user is omitted from the rail entirely — never shown as a broken empty tab. (Historical bug: `switchTab` name-checked `'documents'` literally and intercepted Compete's OLD rail registration, causing a blank panel — Compete no longer registers through this rail since 2.4.2, so it specifically cannot recur for Compete, but this proves any NEW `mvs_dashboard_sections` entrant is safe.)
- **On fail**: the dashboard's JS `switchTab` function — check it dispatches by a registered-sections lookup, not a hardcoded string list (Coding Rule 22).

### 4. Compete hub Gutenberg block matches the standalone page
- **Action**: insert `mvs/pro-compete-hub` on a page as admin; publish; view as a logged-out visitor and as a member; then set `mvs_competitions_enabled = 0` and reload the page containing the block.
- **Expect**: the block renders the SAME `CompeteHubRenderer::render()` output as `/compete/`, wrapped in `get_block_wrapper_attributes()`. Gated off: ordinary visitors see nothing (empty string); a `manage_options` user sees an admin-only notice — never shown to a logged-out or regular-member visitor.
- **On fail**: `src/blocks/pro-compete-hub/render.php` — off-state notice not scoped to `current_user_can('manage_options')`.

### 5. Members-only gate covers all four Compete URLs identically
- **Action**: turn on Free's Members-only community setting; log out; try `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/` directly.
- **Expect**: all four are turned away identically (same login wall/redirect as Explore/Profile) via the `mvs_community_gated_page` filter adding these four query vars.
- **On fail**: `includes/Core/Plugin.php` — one of the four query vars missing from the `mvs_community_gated_page` filter callback.

### 6. The summary endpoint's public-by-design carve-out is intentional, not a leak
- **Action**: with the community private and logged out, curl `/wp-json/mvs-pro/v1/competitions/active-summary` directly.
- **Expect**: refused BEFORE the endpoint's own code runs — Pro adds `/mvs-pro/v1/` to the site-wide private-community REST gate's prefix list, so this public-by-design discovery endpoint does not leak on a private community despite having no explicit permission callback of its own.
- **On fail**: the private-community REST gate's prefix list missing `/mvs-pro/v1/` — this would be a real leak, not the documented carve-out.

## Pass criteria

1. No Compete link is ever auto-injected into site navigation or the dashboard rail.
2. The summary endpoint reflects a write within the same request cycle.
3. Dashboard tab-switching never blanks or leaks a panel, including rapid double-click and back/forward.
4. The Compete hub block's off-state notice is admin-only; on-state output matches the standalone page.
5. All four Compete-family URLs are gated identically on a private community.
6. The summary endpoint is refused on a private community by the site-wide REST gate, not left open.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A "Compete" link appears in nav/dashboard with no manual edit | nav-injection or dashboard-rail registration re-added | grep for `wp_nav_menu_items`/`mvs_dashboard_sections` touching Compete |
| Stale hub data right after a resolve/finalize | cache-version bump missing on that write hook | `includes/REST/CompeteSummaryController.php` |
| A dashboard tab blanks on switch | `switchTab` hardcoded string check | dashboard tab-switching JS |
| Compete block visible to a regular member when off | off-state notice not capability-scoped | `src/blocks/pro-compete-hub/render.php` |
| One of the four Compete URLs slips through the private-community gate | query var missing from `mvs_community_gated_page` filter | `includes/Core/Plugin.php` |

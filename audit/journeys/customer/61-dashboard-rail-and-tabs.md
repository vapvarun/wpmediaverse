---
journey: dashboard-rail-and-tabs
plugin: wpmediaverse
priority: critical
roles: [subscriber, anonymous]
covers: [MV-DSH-001, MV-DSH-002, MV-DSH-003, MV-DSH-004, MV-DSH-005, MV-DSH-006, dashboard-rail, dashboard-tabs]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "My Media dashboard page mapped (mvs_page_dashboard)"
  - "Member A owns a mix of media/albums/collections across privacy levels, plus at least one favourited item"
estimated_runtime_minutes: 7
---

# The My Media dashboard rail has exactly 5 Free sections, each tab lists your own items regardless of privacy, and Favorites is reachable off-rail

## Setup

- Member A (`?autologin=<memberA>`), with own media (mixed privacy), own albums, own collections, and favourites.

## Steps

### 1. Dashboard access and rail (MV-DSH-001)
- **Action**: visit the My Media page (or `[mvs_dashboard]` shortcode page) logged out.
- **Expect**: a premium-styled login gate — icons + primary CTA + `redirect_to` back to the dashboard — NOT a plain "please log in" line.
- **Action**: log in as Member A, revisit.
- **Expect**: the rail shows EXACTLY 5 Free sections: library group (Media, Albums, Collections) + account group (Edit profile). Favorites is NOT on the rail (`nav:false`). The "compete" rail group concept exists but renders nothing on Free-only (no Free section declares it) — confirm it is truly empty, not a dead/empty group heading.
- **Action**: check each rail item's item count.
- **Expect**: Media's count matches your own upload count; a section with 0 items shows a real "0", not a missing rail entry.
- **Action**: rename the mapped dashboard page (Settings > General > Pages) up to 5 times across this test.
- **Expect**: each rename triggers an automatic rewrite-rule flush; bookmarks to any of the last 5 old paths still 301-redirect to the current page (`mvs_dashboard_old_paths` option).

### 2. Media tab (MV-DSH-002)
- **Action**: open the Media tab; search a title fragment; sort by a few options.
- **Expect**: lists EVERY one of Member A's own items regardless of privacy (private, members, public all shown — it's your library, not a public feed); the same shared toolbar (search/sort/count) as Explore. The Edit (cog) icon is visible on cards HERE specifically — confirm it is NOT shown on the same items when they appear on Explore/Album/Collection cards.
- **Action**: compare "no uploads yet" (truly empty) against a search with zero matches.
- **Expect**: two distinct empty-state messages, not the same generic one.

### 3. Albums tab (MV-DSH-003)
- **Action**: open the Albums tab.
- **Expect**: lists all of Member A's own albums at every privacy level with Create/Edit/Delete actions; same empty-vs-filtered distinction as the Media tab.

### 4. Collections tab (MV-DSH-004)
- **Action**: open the Collections tab, including the Favorites card.
- **Expect**: lists Member A's own smart + manual collections plus the Favorites card; the intro copy reads the "gathered by rules you set" framing (Free's `mvs_collections_enabled` default false — see customer/57 step 4), not manual-Save-capable wording.

### 5. Favorites — reachable, not on the rail (MV-DSH-005)
- **Action**: confirm "Favorites" is absent from the main rail (already checked in step 1); reach it via the Collections tab's Favorites card; separately visit `/my-media/favorites/` directly.
- **Expect**: both paths land on the SAME working Favorites listing.
- **Action**: if you have an old bookmark to the pre-2.6.0 rail-item URL for Favorites, visit it.
- **Expect**: it lands somewhere real (redirected or otherwise resolved), never a 404.

### 6. Edit profile panel vs. View profile link (MV-DSH-006)
- **Action**: in the rail's account group, click "Edit profile".
- **Expect**: switches the panel IN PLACE (no navigation away) to the edit form (MV-PRF-011's fields).
- **Action**: click the "View profile" link in the rail head next to your name.
- **Expect**: NAVIGATES AWAY to `/media/@you/` (unlike every other rail item, this one leaves the dashboard) — confirm this distinction holds; a rail item that silently left the page while every other item panel-switched would be a UX regression worth flagging on its own.

## Pass criteria

ALL of the following hold:
1. Logged-out dashboard shows a premium login gate with `redirect_to`; logged-in rail has exactly 5 Free sections with real per-section counts including zero; Favorites is absent from the rail; up to 5 page renames still redirect old bookmarks.
2. Media/Albums tabs list the member's OWN items at every privacy level with a shared toolbar and distinct empty/filtered states; the dashboard-only Edit (cog) icon does not leak onto Explore/Album/Collection cards for the same items.
3. Collections tab shows the Free-appropriate intro copy and lists the Favorites card.
4. Favorites is reachable via both the Collections card and its direct URL, landing on the same listing.
5. "Edit profile" panel-switches in place; "View profile" navigates away — the one deliberate exception in the rail.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Rail shows a "Favorites" item | `nav:false` on the favorites section removed | `includes/Core/DashboardSections.php` |
| A rail section shows no count at all instead of "0" | count rendering skips the zero case | `templates/partials/dashboard-content.php` |
| An old renamed dashboard URL 404s | `mvs_dashboard_old_paths` history not written on rename, or lookup limited to fewer than 5 | `includes/Core/TemplateLoader.php` (old-paths redirect) |
| Edit (cog) icon appears on an Explore card for your own item | card-builder not distinguishing dashboard context from public grids | `assets/js/frontend/card-builders.js` |
| "View profile" panel-switches instead of navigating | rail-head link wired through the same panel router as other items | dashboard rail JS/template |

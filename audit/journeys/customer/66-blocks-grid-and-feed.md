---
journey: blocks-grid-and-feed
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-BLK-001, MV-BLK-002, MV-BLK-003, MV-BLK-004, MV-BLK-005, media-grid-block, explore-feed-block, media-player-block, album-viewer-block, media-stats-block]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A normal (non-Explore) published WP page to embed blocks on"
  - "A public album, a members-only album; a public video/audio item; media with accumulated views/downloads/reactions"
estimated_runtime_minutes: 8
---

# The 5 grid/feed/player/viewer/stats blocks render correctly on an arbitrary page, both as blocks and as their shortcode equivalents

## Setup

- A normal published page `$PAGE` (not the mapped Explore page).

## Steps

### 1. Media Grid block / `[mvs_gallery]` (MV-BLK-001)
- **Action**: insert the "Media Grid" block on `$PAGE` with columns/type/category/tag/order attributes set; publish; view.
- **Expect**: grid renders per the chosen attributes.
- **Action**: on a second page, use `[mvs_gallery type="" category="" tag="" orderby="date" order="desc"]` with equivalent settings.
- **Expect**: visually identical output to the block — EXCEPT the shortcode's effective `columns`/`per_page` are always forced from `mvs_grid_columns`/`mvs_items_per_page` and cannot be overridden by shortcode attributes (verify by passing explicit `columns=`/`per_page=` attributes on the shortcode and confirming they're ignored, while the block's own `columns`/`perPage` attributes DO take effect).
- **Action**: set the shortcode's `layout` to a nonsense string.
- **Expect**: falls through to the default grid — no PHP error/warning on a Free-only install.
- **Action**: filter to a tag/category/type that matches nothing.
- **Expect**: empty state, not a broken/blank area.

### 2. Explore Feed block / `[mvs_explore_feed]` (MV-BLK-002)
- **Action**: insert "Explore Feed" on `$PAGE` (NOT the mapped Explore page) with `showFilters`/`showSearch` on.
- **Expect**: a self-contained discover feed; the embedded search and filter controls work identically to the main Explore page's (MV-EXP-002/004/005).
- **Action**: toggle `filters`/`search` off; reload.
- **Expect**: both controls are hidden entirely, not just disabled.
- **Action**: confirm `$PAGE` itself is not force-gated by `mvs_members_only` (it's an arbitrary owner-embedded page), but the underlying REST data the block fetches still is, when that setting is on.
- **Expect**: page shell loads for a logged-out visitor even with Members Only on, but the embedded feed's data fails to load (empty/error state) — verify this exact split (see also customer/70).

### 3. Media Player block / `[mvs_player]` (MV-BLK-003)
- **Action**: insert "Media Player" pointing at a public video/audio item.
- **Expect**: plays inline.
- **Action**: point it at a private item belonging to someone else, view as a different member.
- **Expect**: one of the defined empty states (not found / wrong privacy) — never a broken player.
- **Action**: point it at a DOCUMENT's media id.
- **Expect**: refused/empty — this block is for playable media only.
- **Action**: with `showDownload`/`download` true, confirm the resulting Download link's behavior against `mvs_allow_downloads` and the item's `allow_download` meta.
- **Expect (code discrepancy, confirmed 2026-09-29)**: `src/blocks/media-player/render.php` renders `<a download href="$file_url">` unconditionally when `showDownload` is set — it does NOT check `mvs_allow_downloads` or the per-item `allow_download` meta, unlike MV-MED-009's single-media-page Download control. This contradicts the catalog's "subject to the same global+per-item download gates as MV-MED-009" claim; the code is the current source of truth. Report this as the same known gap flagged in `customer/46-media-engagement-actions.md` step 7 — do not file it as a new, separate bug.

### 4. Album Viewer block / `[mvs_album]` (MV-BLK-004)
- **Action**: embed the public album's id on `$PAGE`, view logged out.
- **Expect**: renders the album's viewable items via `AlbumService::viewable_item_ids()` — the SAME per-viewer filtering the album's own page and REST route use.
- **Action**: embed the members-only album's id, view logged out.
- **Expect**: denied (no items rendered).
- **Action**: embed a mixed-privacy album (one item private to a stranger) and view as that stranger.
- **Expect**: only the viewable items render — the private item does not leak through this embed, confirming the 2.5.1 authorization unification holds here too.
- **Action**: embed an album with zero viewable items for the current viewer.
- **Expect**: "no items in album" empty state.

### 5. Media Stats block / `[mvs_stats]` (MV-BLK-005)
- **Action**: insert "Media Stats" with all toggles (views/downloads/reactions/top) on.
- **Expect**: each enabled stat card renders, plus a "top media" list of `top_count` items.
- **Action**: toggle each off individually; reload each time.
- **Expect**: each toggle independently shows/hides only its own card.
- **Action**: on a site with zero accumulated stats, insert the block.
- **Expect**: a "no data" placeholder, not a blank/zero-looking chart area.

## Pass criteria

ALL of the following hold:
1. Media Grid block and `[mvs_gallery]` produce equivalent output; the shortcode's columns/per-page are admin-forced while the block's own attributes work; an unrecognised layout falls back to plain grid without error.
2. Explore Feed block's search/filter controls work identically to the main Explore page and hide fully when their attribute is off; the page shell is never force-gated by Members Only, but its embedded data is.
3. Media Player plays public items, shows a defined empty state for inaccessible/wrong-type items, and its Download link's known gap (bypassing both download toggles) is confirmed and cross-referenced, not re-filed.
4. Album Viewer renders exactly the current viewer's viewable items via the canonical per-viewer filter, on both a fully-denied and a mixed-privacy album; shows the correct empty state at zero.
5. Media Stats toggles each card independently and shows a "no data" placeholder rather than an empty-looking chart when there is no data.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Shortcode's `columns`/`per_page` attributes actually change the layout | shortcode not forcing the admin-setting override | `includes/Shortcodes/Shortcodes.php` (gallery shortcode) |
| Explore Feed block's page itself redirects to login with Members Only on | the arbitrary-page exemption in `mvs_members_only` regressed | `includes/REST/CommunityPrivacyGate.php` (page gate scope) |
| A private item renders inside the Album Viewer block for a stranger | block using its own privacy check instead of `AlbumService::viewable_item_ids()` | `src/blocks/album-viewer/render.php` |
| Media Stats shows a zero-looking chart instead of "no data" | missing zero-state branch per card | `src/blocks/media-stats/render.php` |

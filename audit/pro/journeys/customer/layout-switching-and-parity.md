---
journey: layout-switching-and-parity
plugin: wpmediaverse-pro
priority: high
roles: [administrator, anonymous, member]
covers: [MV-LAY-001, MV-LAY-010, MV-LAY-011, MV-LAY-012, MV-LAY-015, MV-LAY-016, layout-parity, layout-mode-switcher]
prerequisites:
  - "Both plugins active; Pro's four skins available"
  - "Auto-login mu-plugin available"
  - "At least 6 published media items, some tagged, spanning a couple of days of created_at"
estimated_runtime_minutes: 10
---

# Every layout skin reads/writes the same feed contract — search, sort, tags, and enqueue

**Why this journey exists**: Instagram, Pinterest, Flickr and Dribbble are meant to be four skins over one shared feed engine (`Frontend\Layouts\AbstractConnectorFeedLayout` for Pinterest/Flickr/Dribbble, a parallel-but-synced `InstagramLayout`), not four independent implementations. Per the catalog (MV-LAY-015), this exact "fixed 3 of 4, missed 1" class of bug has shipped before 2.6.0 — tag filtering worked on three layouts and was silently missing on Instagram; search/tag-cloud has broken the same way twice. This journey audits the four layouts side by side instead of testing them as unrelated features, and separately locks the regression from MV-LAY-016: a feed block's `render.php` must instantiate its own Layout class AND call `enqueue_assets()` in the same file, or the block "looks unstyled" (raw-size SVG stat icons) on any page the site-wide LayoutManager path never runs on.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`
- Settings → Display → "Layout" field: `mvs_pro_feed_layout` option, values `instagram|pinterest|flickr|dribbble`
- Media: tag at least 2 items with the same tag; give items different `created_at` and some `mvs_media_views` rows for sort testing

## Steps

### 1. Cycle every layout through the same audit checklist
- **Action**: for each of `instagram`, `pinterest`, `flickr`, `dribbble`: `wp option update mvs_pro_feed_layout <skin>`; visit `$SITE_URL/explore/` (or the mapped Explore page).
- **Expect**: each layout renders its own card style (per MV-LAY-002..005), but the toolbar — search field, sort dropdown, tag chips, Load More/pagination — is present and behaves identically across all four. No control present on one layout and missing on another.
- **On fail**: `includes/Frontend/Layouts/AbstractConnectorFeedLayout.php` (Pinterest/Flickr/Dribbble base) vs `includes/Frontend/Layouts/InstagramLayout.php` — diverged instead of sharing the same partial.

### 2. Search parity (`?q=`, never `s=`)
- **Action**: under each layout, submit the search field with a known term; then a zero-match term; then toggle the "Media"/"People" search-mode tabs.
- **Expect**: URL carries `?q=<term>` on every layout (never `s=`). Zero-match shows: `No results for "<term>"` + "Try a different keyword or browse by popular tag:" with a Browse-all-media button and up to 8 popular tag chips — identical wording on all four. The "People" results container is hidden by default (`style="display:none"`) and toggles cleanly with no stale results left visible after switching back to Media.
- **On fail**: the layout's own template partial diverged from the shared search resolver — check whichever layout differs against `templates/layouts/<skin>/` vs the shared `explore-filters.php` partial.

### 3. Sort parity (Newest / Oldest / Most viewed)
- **Action**: under each layout, use "Sort by": Newest, Oldest, Most viewed; submit; reload with the resulting URL; confirm an active tag AND an active search term both survive a sort change via hidden fields.
- **Expect**: same three options/labels/default (Newest = `created_at` DESC) on all four; item count shown next to the sort control, hidden entirely at 0 items; sort is a normal GET form submit (full reload), never AJAX.
- **On fail**: a layout's toolbar partial dropped a hidden field carrying the active tag or search term.

### 4. Tag/category chip parity, including direct archive URLs
- **Action**: under each layout, click a real tag chip; then visit an invalid tag slug directly in the URL; then visit `/media-tag/<real-slug>/` directly (bypassing Explore) while that layout is active.
- **Expect**: real tag filters the feed, heading reads `Tag: <name>`; invalid slug shows `Tag "<slug>" not found` with Browse-all-media + popular tags — never a silently-unfiltered full feed. The `/media-tag/*` archive loads the ACTIVE layout's own CSS/JS (not just plain-grid markup with no layout stylesheet). Load More on a tag-filtered feed carries the tag forward to page 2 via a `data-tag` attribute on the Load More button.
- **On fail**: `includes/Frontend/Layouts/InstagramLayout.php` historically dropped the tag filter server-side AND client-side — check the active layout's query-arg handling and its Load More button markup for `data-tag`.

### 5. Cross-layout parity audit sign-off
- **Action**: with the same dataset and viewer, tabulate step 1-4's results across all four layouts.
- **Expect**: zero deltas — every control present, every string byte-identical (empty-state text, sort labels, search placeholder come from one shared source, not four copies).
- **On fail**: whichever layout's template file was most recently touched — re-run this audit specifically after any single layout's feed-body template is edited (this is the documented failure pattern: "fixed 3 of 4, missed 1").

### 6. Block render.php enqueues its own layout assets (MV-LAY-016)
- **Action**: with site-wide Layout = `grid` (i.e. NOT one of the Pro skins), insert the "Dribbble Feed" block (`mvs/pro-dribbble-feed`) on an ordinary page/post; view the published page; open DevTools Network tab.
- **Expect**: `mvs-layout-dribbble` CSS and its JS module are present in loaded assets even though the site-wide layout never runs — the block's own `render.php` instantiated `DribbbleLayout` and called `enqueue_assets()` itself. Stat icons render at the styled 14x14px size (dribbble.css), not raw/oversized SVG viewBox.
- **On fail**: `src/blocks/pro-dribbble-feed/render.php` — missing the direct `enqueue_assets()` call (this is the exact regression: the site-wide `LayoutManager` path used to be the only thing enqueuing these assets, so a block on a random page shipped unstyled).

### 7. Double-enqueue is harmless
- **Action**: with site-wide Layout = `dribbble` AND a Dribbble Feed block on the same page, reload and check the console/network tab.
- **Expect**: no duplicate stylesheet link, no console warning — `wp_enqueue_style`/`wp_enqueue_script` dedupe by handle.
- **On fail**: a layout class enqueuing with a non-deduped inline `<style>`/`<script>` instead of the handle-based WP API.

## Pass criteria

1. Search/sort/tag toolbar controls are present and behave identically across all four layouts.
2. `/media-tag/*` and `/media-category/*` archives load the active layout's own stylesheet, not bare grid markup.
3. Load More carries the active tag forward on page 2+.
4. A feed block's `render.php` enqueues its own layout's assets independent of the site-wide setting (MV-LAY-016) — stat icons never render at raw SVG size.
5. Double-enqueuing the same layout's assets causes no duplication or console warning.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A control present on 3 layouts, missing on the 4th | that layout's template diverged from the shared partial | `includes/Frontend/Layouts/InstagramLayout.php` vs `AbstractConnectorFeedLayout.php` |
| Tag archive renders unstyled | archive template not loading the active layout's assets | `includes/Frontend/GamificationTemplateLoader.php` / the taxonomy archive template |
| Block looks unstyled on a random page | `render.php` doesn't call `enqueue_assets()` directly | `src/blocks/pro-<skin>-feed/render.php` |
| Load More drops the active tag on page 2 | Load More button missing `data-tag` | the layout's feed-card/toolbar partial |

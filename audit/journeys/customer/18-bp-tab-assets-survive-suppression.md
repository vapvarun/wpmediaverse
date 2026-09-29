---
journey: bp-tab-assets-survive-suppression
plugin: wpmediaverse
priority: high
roles: [administrator, subscriber]
covers: [MV-BP-001, MV-BP-002, buddynext-suppression, bp-media-tab, bp-group-media-tab, load-more, frontend-presence]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "BuddyPress (or BuddyNext providing it) ACTIVE - without it these screens do not exist"
  - "BuddyNext active, so `mvs_buddynext_active` is true and the suppression sweep runs"
  - "A member with MORE media than `mvs_items_per_page`, so Load More actually renders"
  - "A group with at least one media item"
estimated_runtime_minutes: 6
---

# MediaVerse's own BP tabs keep their assets when BuddyNext takes over the page

**Why this journey exists**: `Plugin::enforce_frontend_presence()` runs at
`wp_enqueue_scripts@PHP_INT_MAX` and, when BuddyNext owns the page UX,
dequeues AND deregisters every enqueued `mvs-*` handle except those the
`mvs_frontend_presence_keep_handles` filter protects. That is correct for
MediaVerse's own pages, which BuddyNext replaces. It is wrong for the Media
tabs, which are MediaVerse content rendered *inside* a BuddyPress screen.

The allowlist drifted from what the tabs enqueue. `ProfileTabIntegration`
protected two stylesheets while `BaseBPTabIntegration::enqueue_assets()`
enqueued six handles, and `GroupTabIntegration` never registered the filter at
all - so group Media tabs lost everything, stylesheets included, which nobody
had reported.

**The part that makes this hard to test naively**: deregistering a *dependency*
does not just remove that dependency. The dependent still answers
`wp_script_is( $handle, 'enqueued' ) === true` and then prints nothing at all.
Measured: register `main` with dep `dep`, enqueue `main`, deregister `dep` -
`enqueued` stays true, `do_items()` emits 0 bytes. So a check that only asserts
the dependent is "enqueued" passes while the script is silently absent.

## Steps

### 1. Member Media tab keeps every handle
- **Action**: with BuddyNext active, open `/members/<user>/media/` and inspect
  the loaded assets.
- **Expect**: every handle in `BaseBPTabIntegration::TAB_ASSET_HANDLES` is
  present in the page - all nine, including the transitive deps
  `mvs-card-builders`, `mvs-confirm` and `mvs-dropzone`. Assert on the emitted
  `<script>`/`<link>` tags, not on `wp_script_is()`, for the reason above.

### 2. Load More actually loads more
- **Action**: the member must have more media than `mvs_items_per_page`. Click
  **Load More**.
- **Expect**: a second page of tiles appends. Before the fix the button
  rendered and did nothing, because `mvs-load-more`'s script was stripped.

### 3. Delete still confirms
- **Action**: as the profile owner, trigger a destructive action on a tile.
- **Expect**: the styled confirm modal appears. `bp-actions.js` fails CLOSED
  when `window.mvsConfirm` is undefined - it returns `false` rather than
  falling back to native `confirm()` (admin-ux-rulebook Rule 10) - so a
  stripped `mvs-confirm` makes delete silently do nothing.

### 4. The GROUP tab, which was worse
- **Action**: open `/groups/<slug>/media/`.
- **Expect**: the same nine handles, and the tab styled. Before the fix this
  screen lost `mvs-frontend` and `mvs-bp-integration` too, so it rendered as
  unstyled lists.

### 5. The suppression itself still works
- **Action**: on the same BuddyNext site, open a MediaVerse page BuddyNext does
  replace (Explore, dashboard).
- **Expect**: `mvs-*` UI handles are still stripped there. This journey must not
  pass by disabling the sweep - only by exempting the tabs' own assets.

### 6. Member Media tab content is correct, not just its assets (MV-BP-001)
- **Action**: on the member Media tab, switch between its "Media" (default), "Albums", and "Documents" sub-tabs (Documents only present when the documents master switch is on).
- **Expect**: real thumbnails render for each sub-tab (never a raw page URL or a broken `<img>`), matching this member's VIEWABLE uploads for the CURRENT viewer — the same privacy rule as the standalone `/media/@user/` profile (MV-PRF-001). A stranger must see neither more nor less here than on that standalone page.
- **Action**: with the documents master switch OFF, revisit the tab.
- **Expect**: the "Documents" sub-tab is entirely absent (not present-but-empty).
- **Action**: view a sub-tab with nothing to show for this viewer.
- **Expect**: an empty state with no broken `<img>` tags — never a stub tile pointing at nothing.

### 7. Group Media tab content is correct (MV-BP-002)
- **Action**: visit `{group}/media/` for a group whose media privacy is `group`; as a group member.
- **Expect**: group-scoped media renders correctly (real thumbnails, matching MV-BP-001's rendering bar).
- **Action**: as a non-member of a PRIVATE group, visit the same tab.
- **Expect**: "no media in this group" (or denied per the group's own BP visibility) — never the group's actual content.
- **Action**: from the group tab, open a single album within it.
- **Expect**: routes internally (not as a separate BP subnav item, since BP doesn't render nested subnavs in groups) and renders correctly.

### 8. The rule, not the list
- **Action**: add a new `wp_enqueue_script()` to `enqueue_assets()` without
  adding its handle to `TAB_ASSET_HANDLES`.
- **Expect**: the drift check catches it. Every handle the class enqueues must
  appear in the constant - that single list is what both the enqueue and the
  keep-filter read, and it is the whole point of the fix (Coding Rule 22).

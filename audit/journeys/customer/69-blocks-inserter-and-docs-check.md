---
journey: blocks-inserter-and-docs-check
plugin: wpmediaverse
priority: normal
roles: [administrator]
covers: [MV-BLK-015, MV-BLK-016, internal-blocks-hidden, shortcode-docs-accuracy]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-admin access with block editor"
estimated_runtime_minutes: 3
---

# Internal Interactivity-only blocks never appear in the inserter, and the shipped shortcode list matches its documentation

## Setup

- Admin (`?autologin=1`), a page open in the block editor.

## Steps

### 1. Internal blocks are never insertable (MV-BLK-015)
- **Action**: open the block inserter; search each of: "MVS Shared UI", "MVS Dashboard View", "MVS Explore View", "MVS Media Social".
- **Expect**: NONE of the 4 appear in search results — confirm each declares `"supports":{"inserter":false}` in its `block.json`. These are internal Interactivity API stores other blocks/templates depend on, not user-facing blocks.
- **Action**: search for each of the 8 real blocks: Media Grid, Explore Feed, Media Player, Album Viewer, Media Stats, Member Photos, PDF Viewer, Media Upload.
- **Expect**: all 8 ARE found and insertable.

### 2. Documentation matches shipped shortcodes (MV-BLK-016)
- **Action**: `grep -rhoE "add_shortcode\( *'mvs_[a-z_]+'" includes/Shortcodes/Shortcodes.php | sort -u` (or open the file directly) to list every registered shortcode tag.
- **Expect**: exactly these 14 (Free): `mvs_gallery`, `mvs_upload`, `mvs_album`, `mvs_player`, `mvs_stats`, `mvs_dashboard`, `mvs_collection`, `mvs_profile_edit`, `mvs_documents`, `mvs_explore_feed`, `mvs_lock_overlay`, `mvs_member_photos`, `mvs_pdf_viewer`, `mvs_usage_history`.
- **Action**: open `docs/website/features/shortcodes.md` and compare its documented list against the 14 above.
- **Expect**: all 14 are documented by name; any Pro-only shortcode (`mvs_pro_*`) is documented separately and is not conflated with the Free list. Report any name present in code but missing from docs, or vice versa, as a documentation-drift finding — this is not a live-site behavior test.

## Pass criteria

ALL of the following hold:
1. The 4 internal Interactivity-only blocks never appear in the inserter; the 8 real blocks all do.
2. The 14 registered Free shortcodes exactly match `docs/website/features/shortcodes.md`'s Free-side list, with no drift either direction.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| An internal store block appears in the inserter | `supports.inserter` flag missing/reverted in its `block.json` | `src/blocks/shared-ui/block.json`, `dashboard-view/block.json`, `explore-view/block.json`, `media-social/block.json` |
| A registered shortcode is missing from the docs | docs not updated when a shortcode was added/renamed (Coding Rule 25) | `docs/website/features/shortcodes.md` |
| A documented shortcode no longer exists in code | shortcode removed without a docs update | `includes/Shortcodes/Shortcodes.php` |

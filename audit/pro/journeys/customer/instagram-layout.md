---
journey: instagram-layout
plugin: wpmediaverse-pro
priority: normal
roles: [anonymous, member]
covers: [MV-LAY-002, MV-LAY-006, instagram-layout]
prerequisites:
  - "Pro active; mvs_pro_feed_layout = instagram"
  - "A member with published media, some multi-image gallery uploads"
estimated_runtime_minutes: 5
---

# Instagram layout renders a vertical card feed on Explore and on a profile, scoped correctly

## Setup

- `wp option update mvs_pro_feed_layout instagram`
- Member `<owner>` with several published items including one multi-image gallery upload

## Steps

### 1. Explore renders the IG card feed
- **Action**: visit `$SITE_URL/explore/`.
- **Expect**: vertical card feed — author avatar, full-width media, reaction/comment/share row, caption. One card per upload; a multi-image gallery collapses to ONE card (cover-group exclusion), not one card per file.
- **On fail**: `includes/Frontend/Layouts/InstagramLayout.php` cover-group query.

### 2. Load More is idempotent
- **Action**: scroll to trigger Load More; double-click it rapidly.
- **Expect**: spinner shows (`.mvs-load-more-spinner`) and the button disables mid-fetch; button becomes "You're all caught up!" at the last page; double-click does not duplicate cards (idempotent page cursor).
- **On fail**: `src/blocks/pro-instagram-feed` view script / Interactivity store's page-cursor state.

### 3. Empty site shows the shared empty hero
- **Action**: on a site with zero media, visit Explore under Instagram.
- **Expect**: "No media has been shared yet" / "Be the first to share something with the community!" — no crash, no blank white area.

### 4. Profile scoping and viewer-visible count
- **Action**: visit `/media/@<owner>/`.
- **Expect**: same card feed scoped to `author_id = <owner>`, above a profile header (avatar, bio, media count, follower/following counts, Follow, DM gated by messaging privacy). Media count is `count_visible_by_author()` — what THIS viewer can open, not the author's raw total.
- **On fail**: profile header renderer using a raw post-type count instead of the visibility-filtered count.

### 5. Follow button is optimistic, DM entry hides (not disables)
- **Action**: as a different logged-in member, click Follow on the profile.
- **Expect**: label flips immediately (optimistic), then `POST users/{id}/follow` fires in the background; on failure the label reverts, no page reload either way. If messaging privacy disallows DMs from this viewer, the DM entry point is absent entirely (not a disabled/dead button).

### 6. Own profile shows no Follow/DM control
- **Action**: `<owner>` visits their own profile.
- **Expect**: no Follow or DM button (`is_own` short-circuits both).

## Pass criteria

1. One card per upload; galleries collapse to one card.
2. Load More is idempotent and shows correct end-of-feed state.
3. Empty site shows the shared hero, no crash.
4. Profile media count reflects viewer-visible items, not the author's total.
5. Follow is optimistic with rollback-on-failure; DM hides (not disables) when not permitted.
6. Own-profile view shows neither control.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| One card per file in a gallery | cover-group exclusion missing | `includes/Frontend/Layouts/InstagramLayout.php` |
| Double-click duplicates cards | page cursor not idempotent | Instagram feed store JS |
| Profile count includes private items | raw count instead of visibility-filtered | profile header renderer |
| DM button visible but 403s on click | hidden-vs-disabled inverted | profile template DM gate |

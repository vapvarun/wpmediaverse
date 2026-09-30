---
journey: stories-posting-and-viewing
plugin: wpmediaverse-pro
priority: high
roles: [member, anonymous]
covers: [MV-STY-001, MV-STY-002, MV-STY-003, MV-STY-004, MV-STY-005, MV-STY-006, MV-STY-007, stories, ephemeral-content]
prerequisites:
  - "Both plugins active"
  - "Auto-login mu-plugin available"
  - "A followed author (or self) with content eligible to become a story"
estimated_runtime_minutes: 10
---

# Stories can be enabled, posted from three surfaces, and viewed for every media type — without a broken half-rendered state anywhere

**Why this journey exists**: Stories is the one feature whose enable toggle is
deliberately NOT coupled to the Layout setting — `mvs_stories_enabled` drives
REST routes, the admin moderation screen and the mobile app flag independently
of whether the auto-embedded bar shows on the website. This journey walks the
whole posting-and-viewing loop across all three posting surfaces (upload
checkbox, "Your story" tile, manual block placement) and both auto-display
rules, then confirms the fullscreen viewer handles image vs video/audio
progression correctly.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>`.
- `mvs_stories_enabled = 1`.

## Steps

### 1. Enabling Stories via Settings > Display
- **Action**: open Settings > Display, find the "Stories" section, tick Enabled, save.
- **Expect**: saves `mvs_stories_enabled = 1` (string); section intro text reads "Ephemeral 24-hour stories that members post and others view, with 'seen by' receipts."; field description mentions the bar shows only with Instagram layout, but the app works with any layout.
- **On fail**: `includes/Admin/*Settings` Stories field registration.

### 2. Toggle stays visible regardless of Layout — deliberately
- **Action**: set Layout to a non-Instagram skin (grid/Pinterest/Flickr/Dribbble) while Stories is on.
- **Expect**: the Stories checkbox is NOT hidden — it also drives REST routes, the moderation screen and the app flag, independent of Layout.

### 3. Posting from an upload surface
- **Action**: open any upload surface; confirm the "Also share as a story (visible for 24 hours)" checkbox appears next to the tag input; select an image, tick it, submit.
- **Expect**: media is created AND marked as a story with a 24h expiry in one action — no second confirmation step.
- **On fail**: the upload form partial's story checkbox wiring.

### 4. Checkbox is absent on Free-only or with the toggle off
- **Action**: deactivate Pro (or `wp option update mvs_stories_enabled 0`); reload the same upload surface.
- **Expect**: checkbox does not render at all — never shown-then-broken.

### 5. Document type refused defensively even though the UI shouldn't offer it
- **Action**: attempt to POST a document as a story directly via REST with the story flag set.
- **Expect**: 400 "Only photos, videos and audio can be shared as a story."

### 6. "Your story" tile posts in place, no navigation
- **Action**: load a page with the Stories Bar block while logged in; click the "Your story" tile; pick an image.
- **Expect**: tile shows a spinner in place of the "+" badge and the label switches to "Posting…"; on success the page performs a full reload (not a targeted DOM insert) and the new story appears in the bar.
- **On fail**: the Stories Bar block's `context.posting` interactivity state.

### 7. Failed tile-post resets cleanly
- **Action**: simulate a failed post (e.g. an oversized/invalid file, or a network block).
- **Expect**: tile returns to its normal "+ Your story" state, never stuck showing "Posting…" — the reset runs unconditionally outside the try/catch.

### 8. Anonymous visitors see no tile, no bar, when there is nothing to show
- **Action**: as a logged-out visitor with zero active stories in view, load the same page.
- **Expect**: the whole block renders nothing — no empty shell, no "Your story" tile for a non-member.

### 9. Auto-display on the Instagram layout, absence elsewhere
- **Action**: set Layout to Instagram with Stories on; visit Explore. Then turn Stories off and reload. Then set Layout to a non-Instagram skin with Stories still on and reload.
- **Expect**: Instagram+Stories-on shows the real bar (not a legacy "recent uploaders" placeholder). Stories-off shows nothing, no gap. Layout != Instagram: the AUTO-embedded bar never appears on the website even with Stories fully on — by design, not a bug.

### 10. Manual placement works on any layout
- **Action**: with Layout set to Pinterest/Flickr/Dribbble/grid, manually insert the "Stories Bar" block (`mvs/pro-stories`) on any page.
- **Expect**: renders and behaves identically to the Instagram auto-embed — the "Instagram only" language describes the AUTO-embed, not a restriction on the block itself.

### 11. Viewing an image story
- **Action**: click a story avatar in the bar; observe the viewer.
- **Expect**: opens as a real dialog (`role="dialog"`, `aria-modal="true"`); a segmented progress bar shows one segment per story in the set; landscape/square images fill the viewer.

### 12. Viewing video/audio stories
- **Action**: open a video story; confirm autoplay starts muted with native controls visible; unmute; let it play to end; repeat for an audio story.
- **Expect**: progress segment fills in sync with real playback time (`timeupdate`), not a fixed clock; audio-only stage shows a distinct visual anchor rather than a blank image area. If muted autoplay is refused, falls back to a timer-driven advance rather than stalling.

### 13. Author's own view never counts toward "seen by"
- **Action**: post a story, then immediately view it as the author.
- **Expect**: "seen by" count for that story is unaffected by the author's own view.

## Pass criteria

1. The toggle saves and stays visible regardless of Layout.
2. All three posting surfaces (upload checkbox, "Your story" tile, manual block) work and none appears when it shouldn't (Pro inactive, toggle off, no content).
3. A failed tile-post always resets to its normal state.
4. Auto-display fires only on Instagram+Stories-on; manual placement works on every layout.
5. Image and video/audio viewing both progress correctly with no stalled/frozen state.
6. Author's own view of their own story is never counted.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Checkbox shows with Pro inactive | `stories_available()` not checking `defined('MVS_PRO_VERSION')` | Free's upload-form partial |
| "Your story" tile stuck on "Posting…" after a failure | reset not outside try/catch | Stories Bar block's interactivity JS |
| Auto bar shows on a non-Instagram layout | template inclusion not scoped to Instagram | Instagram feed template |
| Manual block behaves differently from the auto-embed | block render path diverges from the auto-embed path | `mvs/pro-stories` block `render.php` |
| Video story stalls with no progress | autoplay-refused fallback missing | Stories viewer JS (`avTimed` fallback) |
| Author's own view increments "seen by" | author-exclusion missing in the view-record path | `POST /stories/{id}/view` handler |

---
journey: bp-activity-integration
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-BP-003, MV-BP-004, MV-BP-005, MV-BP-006, MV-BP-007, MV-BP-008, MV-BP-009, bp-activity-sync, bp-activity-privacy, bp-composer, bp-notification-bell, buddynext-handoff]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "BuddyPress active with the Activity component; a stranger, a friend, and a fellow group member available"
  - "Optional: BuddyNext active for step 6/7"
estimated_runtime_minutes: 9
---

# Uploads sync into BuddyPress activity honestly, privacy filters every viewer, the composer looks right, and the notification bell has one source

## Setup

- Member A (`?autologin=<memberA>`), Members B (stranger) and C (friend or group co-member).

## Steps

### 1. Upload creates a BP activity item, private/DM never leaks (MV-BP-003)
- **Action**: as Member A, upload a `public` item.
- **Expect**: a real `bp_activity_add()` entry appears in the site-wide activity stream.
- **Action**: upload a `private` item, then a `dm`-privacy item (a DM attachment).
- **Expect**: NEITHER writes an activity row at all — confirmed in code (`ActivitySyncIntegration` skips both `private` and conversation-scoped `dm` uploads). DM attachments must never surface in the public activity stream.
- **Action**: measure the activity card's media preview for a single-image upload, and separately for a 2-6 image gallery upload, at desktop and at ≤640px.
- **Expect**: single image is a FIXED 64px square (never a 200-320px "hero" preview regardless of file count — this is a locked visual spec, measure it, don't eyeball it); 2-6 images use a CSS grid with per-count column templates, collapsing to 2 columns at ≤640px.
- **Action**: delete the uploaded public item.
- **Expect**: its activity entry is cleaned up too — no dead activity card advertising deleted content.

### 2. Comment syncs into BP activity comments (MV-BP-004)
- **Action**: comment on the public item's own single-media page.
- **Expect**: the comment mirrors into the activity card's own BP comment thread (via `bp_activity_add()` for the comment) — a stream follower sees the conversation without visiting the media page.
- **Action**: edit, then delete, the original comment from the media page (within MV-MED-006's window for edit).
- **Expect (report the actual behavior)**: check whether the edit/delete propagates to the mirrored BP activity comment, or whether the two drift apart — the catalog flags this as an open question, not a guaranteed guarantee.

### 3. Activity privacy filtering matches media privacy (MV-BP-005)
- **Action**: upload items at public, members, friends, group, and private privacy (as applicable). As Member B (a stranger to A), scroll the site-wide activity stream, then A's profile activity, then (if applicable) group activity.
- **Expect**: only items whose privacy Member B can actually view appear as activity cards, across ALL THREE surfaces — a stranger never sees a friends-only or group-only upload's card anywhere.
- **Action**: as Member C (a friend or group co-member with access), repeat.
- **Expect**: C sees the friends/group items B could not.
- **Action**: paginate the site-wide stream (load-more) past page 1, as Member B.
- **Expect**: a correctly-hidden item on page 1 does NOT leak through on page 2+ — filtering holds across pagination, not just the first page.

### 4. Activity composer's attach-media control (MV-BP-006)
- **Action**: open the "What's new" composer; locate "Attach media".
- **Expect**: a proper button — NOT icon-only — with a visible "Attach media" label, an image-plus icon, and an `aria-label`. It sits in the SAME row as the activity's own privacy select, both at a consistent height and border style.
- **Action**: if wb-reign-theme is available, repeat this check specifically on that theme.
- **Expect**: no vertical misalignment between the button and the privacy select — this is the documented regression source for this exact check.

### 5. BuddyPress notification bell is the single source (MV-BP-007)
- **Action**: with BuddyPress active, have Member B follow Member A (triggers a `new_follower` notification); check the BP nav bell AND the My Media dashboard's own bell as Member A.
- **Expect**: ONLY the BP nav bell shows it; the dashboard's own `.mvs-notification-bell` is suppressed entirely on a BP-active site (see customer/62 step 1) — no duplicate count/entry visible across both surfaces at once.

### 6. Handing the frontend to BuddyNext (MV-BP-008) — informational
- **Action**: if BuddyNext (or an equivalent host) is active and sets `mvs_buddynext_active` true, note that MediaVerse stands its own assets/panels/bell down entirely, deferring UX to the host.
- **Expect**: on plain BuddyPress or standalone Free (this filter false/absent), MediaVerse renders its own full chrome as normal — confirm this filter is genuinely false on a plain-BuddyPress-only test session; a true value without BuddyNext installed is a misconfiguration to flag, not a MediaVerse bug.

### 7. Reactions/mentions in the BuddyNext bell (MV-BP-009) — only with BuddyNext active
- **Action**: as Member B, react to Member A's public photo; as Member C, react too.
- **Expect**: exactly ONE bell row for A reading "C and 1 other reacted to <photo title>" (repeats merge into one row, moving to the top), linking to the photo.
- **Action**: as Member B, mention Member A in a comment on the same photo.
- **Expect**: one separate mention row.
- **Action**: make the photo private; view A's bell as B's own session (B can no longer see the photo).
- **Expect**: the row STAYS for A (who can still view it); a viewer who cannot see the photo never sees a row about it.
- **Action**: switch "Reactions to your media" OFF in A's BuddyNext notification preferences; have another member react again.
- **Expect**: no new bell row; MediaVerse's own activity email (if enabled) is unaffected — the BuddyNext toggle controls only its own bell.
- **Action**: permanently delete the photo.
- **Expect**: every bell row about it disappears (trash/private only HIDES a row from viewers who lose access; only permanent delete REMOVES the rows).
- **Action**: as Member A, react to A's OWN photo.
- **Expect**: no row created (no self-notification). Confirm blocked members never notify each other via this path either.

## Pass criteria

ALL of the following hold:
1. Public uploads create an activity row; private/DM uploads create none; the locked 64px-single / grid-multi preview spec holds at desktop and ≤640px; deleting media cleans up its activity row.
2. Media comments mirror into the BP activity's comment thread; edit/delete propagation is checked and reported honestly.
3. Activity privacy filtering matches the media item's own privacy across the site-wide stream, profile, and group activity, and holds across pagination.
4. The composer's Attach-media control is a labeled, accessible button aligned with the privacy select, including on Reign.
5. Only the BP nav bell shows MediaVerse notifications when BuddyPress is active; the dashboard bell is suppressed.
6/7. (BuddyNext-only, if active) exactly one merged bell row per item+type; visibility follows current view rights; the BuddyNext toggle controls only its own bell; permanent delete removes all rows; no self-notifications.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A `private` or `dm` upload creates an activity row | the privacy/dm skip-check removed | `includes/Integrations/BuddyPress/ActivitySyncIntegration.php` |
| Activity preview shows a large "hero" image for a single upload | the fixed-64px rule dropped from the activity card CSS | `assets/css/bp-integration.css` |
| A friends-only/group upload's card visible to a stranger on page 2+ | privacy filter applied only to the first fetch, not `bp_activity_get`'s pagination path | `includes/Integrations/BuddyPress/ActivityPrivacyFilter.php` |
| Attach-media button misaligned with the privacy select on Reign | theme-specific `<select>` height/border not matched in `bp-integration.css` | `assets/css/bp-integration.css` |
| Both the dashboard bell and BP bell show notifications simultaneously | BP-active suppression check missing | `templates/partials/dashboard-content.php` |
| Deleted media's activity card still shows | delete path not firing the activity-cleanup hook | `includes/Integrations/BuddyPress/ActivitySyncIntegration.php`, `includes/Repository/MediaRepository.php::delete_cascade()` |

---
journey: album-create-and-privacy-cascade
plugin: wpmediaverse
priority: critical
roles: [subscriber]
covers: [MV-ALB-001, MV-ALB-002, MV-ALB-003, MV-ALB-004, MV-ALB-005, album-create, album-privacy-cascade, album-privacy-confirm, one-album-per-photo]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Member A owns 3+ photos with varied individual privacy (at least one Members Only)"
  - "mvs_allow_user_privacy ON"
estimated_runtime_minutes: 8
---

# Creating an album, and the 2.6.0 two-way privacy cascade between an album and its photos

**Why this matters**: before 2.6.0, album privacy was a one-way clamp — tightening an album tightened its photos, but re-opening the album could leave photos private forever. `AlbumService::apply_album_privacy()` now pushes the album's privacy onto every member photo in BOTH directions, keeping each photo's own choice aside in `own_privacy` meta the first time it's overridden.

## Setup

- Member A (`?autologin=<memberA>`), owns Photo 1 (own privacy: Public), Photo 2 (own privacy: Members Only, set BEFORE joining any album), Photo 3 (own privacy: Public).

## Steps

### 1. Create an album (MV-ALB-001)
- **Action**: My Media dashboard → Albums tab → Create; leave Title empty and try to Save.
- **Expect**: Save is blocked client-side with a clear message (empty-title guard); no request sent.
- **Action**: fill Title "Journey Album", optional description, Privacy = Public; select Photo 1 and Photo 3 from the picker; Save.
- **Expect**: `POST /mvs/v1/albums` returns 201; toast "Album saved!" (or equivalent); the album appears in the Albums tab AND at `/album/<slug>/` without a full page reload.

### 2. Album privacy tightens its photos (MV-ALB-002, direction 1)
- **Action**: edit "Journey Album", change privacy Public → Members Only, Save.
- **Expect**: `PUT /mvs/v1/albums/{id}` succeeds; `mysql_query "SELECT privacy FROM wp_mvs_media_index WHERE media_id IN (Photo1, Photo3)"` shows BOTH now `members`; the same is confirmed by viewing each photo's single-media page as a logged-out visitor (denied) and as a member (allowed).

### 3. The confirm dialog fires when WIDENING would expose a stricter photo (MV-ALB-003)
- **Action**: add Photo 2 (own privacy Members Only — same as the album right now, so no prompt yet) to "Journey Album"; then attempt to change the album's privacy from Members Only to Public and click Save.
- **Expect**: BEFORE saving, the client calls `GET /albums/{id}` and reads `own_privacy_counts`; since Photo 2's own privacy (Members Only) is stricter than the new target (Public), a confirm dialog appears INSTEAD of saving immediately: wording naming the count and the target privacy label, with a "Save anyway" button (not the generic "Confirm"). Cancel has initial focus.
- **Action**: click Cancel (or Esc).
- **Expect**: album privacy remains unchanged (still Members Only); no photo privacy was altered.
- **Action**: repeat and click "Save anyway".
- **Expect**: save proceeds; Photo 2 now shows Members Only... wait — the album is now Public, so per the cascade rule Photo 2 becomes Public too (widened). Confirm via DB: `mysql_query "SELECT privacy FROM wp_mvs_media_index WHERE media_id=<Photo2>"` == `public`.

### 4. Tightening never prompts
- **Action**: change "Journey Album" back to Members Only and Save.
- **Expect**: NO confirm dialog — the prompt only fires when widening visibility.

### 5. One album per photo (MV-ALB-004)
- **Setup**: create a second album "Journey Album B" (any privacy).
- **Action**: add Photo 1 (currently in "Journey Album") to "Journey Album B"; Save.
- **Expect**: success toast reports how many photos were moved from other albums (1); `mysql_query "SELECT album_id FROM wp_mvs_album_items WHERE media_id=<Photo1>"` returns ONLY "Journey Album B"'s id — Photo 1 is gone from "Journey Album".
- **Action**: re-add Photo 1 to "Journey Album B" (the SAME album it's already in).
- **Expect**: no-op — no duplicate row, no re-triggered "moved" count, no re-triggered privacy cascade.

### 6. Own privacy restored on leaving an album (MV-ALB-005)
- **Action**: remove Photo 2 from "Journey Album" (its "remove item" control, not delete-album).
- **Expect**: Photo 2's privacy reverts to what Member A originally chose BEFORE it ever joined an album — Members Only, per Setup — not the album's current privacy nor the site default. Verify via the photo's own edit modal / single-media page, no separate confirm needed (this is a release, not a widening).
- **Action**: delete the entire "Journey Album".
- **Expect**: EVERY remaining member photo is released the same way (own privacy restored) before the album post itself is removed; the underlying photos remain in the media library.

## Pass criteria

ALL of the following hold:
1. Album create is title-required (client + server); appears in both surfaces on save.
2. Album privacy pushes onto every member photo in both directions (tighten AND widen).
3. Widening triggers the Cancel-focused confirm dialog naming the affected count and privacy label; Cancel/Esc changes nothing; "Save anyway" proceeds. Tightening never prompts.
4. Joining a second album removes a photo from every other album it was in, with a "moved" count; re-adding to the same album is a silent no-op.
5. Removing a photo from an album (or deleting the album) restores its own originally-chosen privacy, not the album's last privacy or the site default.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Widening an album does not update its photos | `apply_album_privacy()` only handles the tightening branch | `includes/Services/AlbumService.php::apply_album_privacy()` |
| No confirm dialog before a widening save | `own_privacy_counts` not fetched, or the count comparison inverted | `src/blocks/dashboard-view/view.js` (album edit modal save flow), `includes/Services/AlbumService.php::own_privacy_counts()` |
| Confirm dialog fires on a TIGHTENING change too | widen/tighten direction check missing | dashboard album save flow |
| A photo stays in two albums simultaneously | the `DELETE ... WHERE media_id IN (...) AND album_id <> {this}` step removed | `includes/Services/AlbumService.php` |
| Removed photo keeps the album's privacy instead of its own | `restore_own_privacy()` not called on item-remove/album-delete | `includes/Services/AlbumService.php::restore_own_privacy()` |

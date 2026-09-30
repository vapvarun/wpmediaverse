---
journey: advanced-privacy
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber]
covers: [MV-PPV-001, MV-PPV-002, MV-PPV-003, MV-PPV-004, MV-PPV-005, MV-PPV-006, MV-PPV-007, MV-PPV-008, MV-PPV-009, advanced-privacy, privacy-lock, privacy-presets]
prerequisites:
  - "Both plugins active"
  - "Two members (owner + other), one admin/moderator"
  - "Free's mvs_allow_user_privacy setting available (Settings > General, default ON)"
  - "An album with at least one media item that can inherit its privacy"
  - "A connected Flickr account for the sync step (see flickr-connector.md)"
estimated_runtime_minutes: 12
---

# Pro's own privacy routes honour Free's lock exactly; presets and album inheritance behave precisely; one privacy level is a documented, unfixed bug

**Why this journey exists**: Pro adds a second set of privacy-writing routes (`PUT /media/{id}/privacy`, `POST /media/bulk-privacy`) alongside Free's own media routes, and they must honour the SAME lock with the SAME error code — a client should never need to know which namespace it called to predict the lock's behaviour. Separately, this journey documents `followers` privacy as it actually behaves today (broken) rather than assuming a fix exists.

## Setup

- Site: `$SITE_URL`; owner `?autologin=<owner>`, other member `?autologin=<other>`, admin/moderator `?autologin=admin`.

## Steps

### 1. Update privacy on a single item — ownership rules
- **Action**: as owner, `PUT /wp-json/mvs-pro/v1/media/{id}/privacy {privacy: "friends"}`; as a different member, attempt the same; as admin/moderator, attempt on someone else's item.
- **Expect**: owner succeeds (200, message `Privacy updated to "<Label>".`); different member gets 403 `mvs_pro_forbidden`; admin/moderator succeeds. Logged out gets 401 `mvs_pro_unauthorized`; a nonexistent id gets 404 `mvs_pro_not_found`.

### 2. Re-submitting the unchanged current level always succeeds under the lock
- **Action**: with the lock ON (see step 4), as the owner, PUT the item's CURRENT privacy level unchanged.
- **Expect**: succeeds — the lock only blocks an actual CHANGE, not a no-op resubmit from an unrelated field edit.

### 3. `custom` level with an empty `custom_users` array does not clear the list
- **Action**: set an item to `custom` with a non-empty user list; then PUT `custom` again with an EMPTY `custom_users` array.
- **Expect**: the previous custom list is left in place — the code only rewrites it for a non-empty request. Confirm this doesn't silently surprise a member expecting the empty submission to mean "no one."

### 4. Privacy lock: Free's "Allow Users to Set Privacy" honored by both Pro routes
- **Action**: turn `mvs_allow_user_privacy` OFF. As a regular member: (a) attempt a single-item privacy CHANGE via Pro's route; (b) attempt a bulk-privacy change; (c) resubmit the current level unchanged; (d) as admin/site owner, attempt both (a) and (b).
- **Expect**: (a) 403 `mvs_privacy_locked`, "Privacy is set by the site owner, so it cannot be changed here." — same code/message Free's own routes use. (b) refused OUTRIGHT before even checking ownership — no partial processing. (c) succeeds. (d) admin/`manage_mvs_settings` is exempt entirely.
- **On fail**: `PrivacyUIService`/Pro's privacy routes not calling Free's lock-check method.

### 5. Lock check degrades gracefully against an older Free version
- **Action**: (structural check, code-level) confirm Pro checks `method_exists()` before calling Free's lock-check method.
- **Expect**: defaults to ALLOWING the change if Free doesn't support the lock at all, rather than erroring.

### 6. Bulk privacy update — per-item ownership, honest reporting
- **Action**: as a member, `POST /media/bulk-privacy` with up to 100 media IDs, some owned, some not.
- **Expect**: non-owned items are silently split into `skipped`, never erroring the whole batch; owned items land in `updated`. If ZERO items updated, response is still 200 with `success: false` and "No items were updated. You may not have permission to change privacy on the selected items." A caller must read `success`/`skipped.length`, not just HTTP status.
- **Also**: confirm there is NO bulk privacy action on the wp-admin media list — this lives only in the My Media bulk bar (Free) and Pro's REST route.

### 7. "Followers Only" is not actually enforceable — confirm the documented bug, don't invent a fix
- **Action**: set an item's privacy to `followers` via `PUT /media/{id}/privacy` (Pro accepts it). As one of the owner's actual followers, attempt to view it.
- **Expect**: the item is invisible to EVERYONE except the owner and moderators — Free's `PrivacyService::can_view()` has no `case 'followers'` and falls to `default: return false`, functionally identical to "Only Me" despite the UI's description promising followers can see it. This is a known documented gap; the journey's job is to confirm current behaviour, not assert a fix.
- **On fail (i.e., if it now works)**: note the fix location and update this journey's assertion — don't silently leave a stale "known bug" note once it's actually fixed.

### 8. Privacy presets: save, cap at 20, retrieve
- **Action**: `POST /wp-json/mvs-pro/v1/privacy/presets` repeatedly until 20 exist; attempt a 21st.
- **Expect**: 201 with the saved preset object up to 20; the 21st (or an empty name) returns 400 `mvs_pro_preset_save_failed`. Presets are API-only — no site screen shows them, no default preset exists.

### 9. Presets: exact-match relevance surfaced in the media response
- **Action**: save a preset matching an item's current privacy exactly (for `custom`, an exact sorted user-list match); fetch that item's REST response.
- **Expect**: the preset's id appears in `privacy_preset_ids`; a partial user-list overlap for `custom` does NOT count as matching. Media authored by a deleted user returns an empty array cleanly.

### 10. Album-level "inherit album privacy," both directions
- **Action**: set a media item to inherit its album's privacy (`inherit_album`); change the ALBUM's privacy public <-> restricted; confirm the item's effective visibility follows.
- **Expect**: owner/moderator always pass regardless of inheritance; non-privileged viewers resolve against the album via the correct (Space CPT) id-space — never a coincidental numeric-id collision with an unrelated row. Removing the item from an inheriting album restores its PRE-inheritance privacy, not a default.
- **On fail**: `PrivacyUIService::check_album_inheritance()` id-space handling.

### 11. Flickr sync no longer overwrites privacy while the lock is on
- **Action**: connect Flickr; with `mvs_allow_user_privacy` OFF, re-sync a photo whose Flickr-side visibility differs from its current MediaVerse privacy; confirm privacy is NOT overwritten. Turn the lock OFF (i.e., allow-privacy ON) and repeat — confirm it now DOES sync from Flickr's flags.
- **Expect**: title/description/tags still sync regardless of the lock; only the privacy overwrite is skipped when locked, silently and correctly (no visible notice needed).
- **Edge case**: a photo synced for the FIRST time while locked lands on the site's configured Default Privacy Level, not Flickr's flag or empty.

### 12. GDPR export/erase covers Pro's privacy-related data
- **Action**: give a member push tokens, saved collections, and privacy presets; run Tools > Erase Personal Data, then Export.
- **Expect**: erase removes device tokens (so a "forgotten" member stops receiving pushes), boosts, collections, and playback records; competition entries other members participated in are RETAINED with identity removed, not deleted outright. Export lists saved privacy presets and streak counters, never connector tokens. Erase removes presets, streaks, video resume positions, and connected-account tokens.
- **Also**: confirm Pro's own exporter/eraser rows ("MediaVerse Pro settings") actually appear in the standard admin Personal Data Export/Erasure request results list, not just claimed in privacy-policy prose.

## Pass criteria

1. Both Pro privacy routes honour the SAME lock, SAME error code as Free; a no-op resubmit always succeeds under the lock; admin is exempt; bulk refuses outright rather than partially.
2. Bulk privacy correctly splits owned/skipped and reports honestly even at zero-updated.
3. `followers` privacy's current (broken) behaviour is confirmed and documented, not silently assumed fixed.
4. Presets cap at 20, match exactly (including `custom` user-list equality), and surface correctly in the media response.
5. Album inheritance resolves via the correct id-space in both directions and restores pre-inheritance privacy on removal.
6. Flickr sync skips only the privacy field while locked; other fields still sync.
7. GDPR export/erase correctly covers presets, streaks, resume positions, tokens, and retains-with-anonymization for shared competition entries.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Lock message/code differs from Free's own routes | Pro re-implemented the lock instead of calling Free's shared check | Pro privacy route lock-check call |
| Bulk privacy partially processes under the lock | bulk route checks ownership before the lock | bulk-privacy route ordering |
| `custom` empty-array submission wipes the list | code path doesn't guard "only rewrite for non-empty" | privacy route custom-list handler |
| Preset match logic accepts a partial `custom` overlap | comparison not doing an exact sorted-list match | preset relevance resolver |
| Album inheritance resolves against the wrong row | album id resolved in the wrong CPT id-space | `PrivacyUIService::check_album_inheritance()` |
| Flickr sync overwrites privacy while locked | sync path not calling `user_may_choose_privacy()` | Flickr connector sync path |

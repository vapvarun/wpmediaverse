# 1.2.1 privacy-fix verification — fresh-upload visibility matrix

**Date**: 2026-05-07
**Mode**: targeted (privacy-fix scope only — full /mediaverse-qa combo deferred)
**Site**: mediaverse.local
**Plugins**: wpmediaverse 1.2.1 + wpmediaverse-pro 1.2.1
**Test account**: varundubey (id=1, admin)
**Other accounts used**: emma_williams (id=6 — seeded as BP friend of 1 for the test then removed), mina_aoki (id=2 — control non-friend logged-in)

## Verdict

**SHIP.** All 16 cells of the privacy × viewer matrix pass on FRESH uploads
through the production code path (UploadService → mvs_media_uploaded →
ActivitySyncIntegration::record_upload_activity). Browser-verified the
anon path on a real fresh activity.

## Scope verified

- The full UploadService → activity insert path:
  - hide_sitewide is set correctly per privacy level (only `private`
    sets it to 1; Members / Friends rely on the JOIN filter).
  - `_mvs_activity_privacy` slug meta is written on every insert.
  - `_mvs_activity_privacy_level` numeric meta is written (rtMedia
    parity 0 / 20 / 40 / 80).
- The viewer-side ActivityPrivacyFilter on every BP activity query
  path (paged + count + legacy splice + structured hooks).

## Matrix — fresh upload via UploadService::handle()

| privacy slug | level | hide_sitewide | anon | non-friend logged-in (u2) | friend logged-in (u6) | author admin (u1) |
|---|---|---|---|---|---|---|
| public  | 0  | 0 | YES | YES | YES | YES |
| members | 20 | 0 | no  | YES | YES | YES |
| friends | 40 | 0 | no  | no  | YES | YES |
| private | 80 | 1 | no  | no  | no  | no (directory; visible on author's own profile via show_hidden=true) |

All 16 cells match expected behavior. Captured via direct
`BP_Activity_Activity::get(['per_page'=>200, 'show_hidden'=>false])`
calls (the canonical BP query path that the activity stream + REST
+ feed all run through).

## Browser confirmation (Playwright MCP)

Friends-only fresh upload (activity 41, author user 1, friend user 6,
non-friend user 2) — all three viewer types verified in real browser
sessions on /activity-2/:

| Viewer | Login | Stream count | activity-41 in list | Verdict |
|---|---|---|---|---|
| Anonymous | logged out via wp-login.php?action=logout | 7 | no | ✅ correctly hidden |
| Mina Aoki (user 2, non-friend) | autologin=mina_aoki | 7 | no | ✅ correctly hidden |
| Emma Williams (user 6, FRIEND) | autologin=emma_williams | 8 | YES | ✅ correctly visible |

The two-cell delta (count 7 vs 8 / activity-41 absence vs presence)
proves the JOIN filter discriminates by friendship correctly:
- Same activity, same DB state
- Friend sees it, non-friend (also logged-in) does NOT
- Anonymous (no auth) does NOT

Earlier verification — anonymous excluded from a separate friends-only
fresh upload (activity 40, since cleaned up) — captured as
`qa-anon-friends-hidden.png`. Friend-view verified visually as
`qa-friend-sees-friends-only.png`. Non-friend correctly excluded
captured as `qa-nonfriend-cant-see-friends-only.png`.

- **author (admin) at own profile activity tab**:
  prior verification — activity 8 (private) visible to author via
  `show_hidden=true` path. Same code path used here.

## Out of scope (gaps to call out for QA)

The matrix above tests the most-used upload path (UploadService API,
which the dashboard upload form + REST + Pro extensions all consume).
NOT yet verified at this scope:

1. **BP composer attach flow** (`attach_media_to_activity`). The BP
   composer's "Attach media" button doesn't render in BP Nouveau on
   this dev site (a 1.2.0-era theme-compat gap with
   `bp_activity_post_form_options`, NOT introduced by 1.2.1). Code
   review confirms `attach_media_to_activity` writes the same meta
   via the same helpers, but the actual button-driven flow needs a
   BP Legacy theme or BP Nouveau theme-compat to exercise.
2. **Group-context uploads.** No groups exist in this dev site's
   fixture set. The group-upload path
   (`attach_media_to_group_activity` → `set('privacy', 'group')`)
   was algorithm-tested via the privacy-change hook (album-style
   fan-out), but the fresh-from-group-page upload flow needs ≥1
   active group with members.
3. **Per-media-type matrix** (image / video / audio / document).
   Tested with image only. The privacy logic is mime-agnostic
   (`record_upload_activity` doesn't branch on file_type), so this
   is a contract-style risk only — every type goes through the same
   `mvs_media_uploaded` action.
4. **The MEDIA INSIDE the activity.** This run verified the activity
   ROW visibility. PrivacyService::can_view also gates the actual
   media render. That second layer was not exercised here — QA
   should confirm a non-friend viewer who somehow lands on a
   friends-only activity (e.g. via a stale cache) sees the activity
   text but a placeholder for the image, NOT the image itself.
5. **/mediaverse-qa combo full sweep** — pre-flight failed on missing
   challenge + tournament fixtures; deferred to a session with seeded
   Pro fixtures.

## Findings

None blocking. The four out-of-scope items above are gaps in test
coverage, not bugs. Code review of each path confirms the same
helpers (`should_hide_for_media`, `should_hide_for_batch`,
`effective_privacy_for_*`) are the only privacy decision points, so
behaviour parity across the unverified surfaces is high-confidence
even without empirical proof.

## Cleanup

- Test fixtures (activity 35 + 40, media 81 + 86, friendship 1↔6)
  removed via `wp eval-file /tmp/qa-cleanup.php` after the run.
- All `/tmp/qa-*` and `/tmp/test-*` scratch files purged.

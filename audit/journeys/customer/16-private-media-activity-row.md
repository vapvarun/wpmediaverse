---
journey: private-media-activity-row
plugin: wpmediaverse
priority: high
roles: [author, member]
covers: [mvs_activity, privacy-gate, activity-feed, fc31bf0]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "One member who can upload (autologin via ?autologin=<username>)"
  - "A second member to verify cross-user visibility"
estimated_runtime_minutes: 4
---

# Uploading PRIVATE media creates a privacy-gated `mvs_activity` row (not a sitewide one)

**Why this journey exists**: Before 1.7.0, the upload flow skipped writing an `mvs_activity` row entirely for non-public media (commit `fc31bf0` reworked this). The skip meant a member's own activity history was incomplete — their private uploads left no trace in `mvs_activity`, so any future "my activity" surface or REST `GET /users/{id}/activity` for the owner would under-report. The fix writes the activity row for private media too, but the row must stay **privacy-gated**: it records the event for the owner/feed JOIN, yet must never surface that private item to other members or anonymous viewers (the same privacy contract `mvs_media_index` enforces). This journey locks both halves — the row IS written, AND it stays gated. The journey IS the regression test. (Commit `fc31bf0`; tracked in `FLOW-DATA-AUDIT-free-2026-06-15.md` §3 assertions 33-36.)

## Setup

- Site: `$SITE_URL`
- Uploader: `<member-a>` (autologin via `?autologin=<member-a>`)
- Observer: `<member-b>` (a different member, used in step 4)
- Fixtures needed: a small test image to upload.
- DB inspection table: `wp_mvs_activity` (column `media_id` links to `wp_mvs_media_index.media_id`).

## Steps

### 1. Upload a PRIVATE media item as member A
- **Action**: `curl -X POST -H 'X-WP-Nonce: $NONCE' -F 'file=@test.jpg' -F 'privacy=private' $SITE_URL/wp-json/mvs/v1/media` (run authenticated as member A; or upload via the dashboard upload block with privacy set to Private).
- **Expect**: HTTP 201 with the new media JSON; capture the id.
- **Capture**: `PRIVATE_MEDIA_ID` ← `.id` of the response.
- **On fail**: `includes/Services/UploadService.php` (upload pipeline), `includes/REST/Controller/MediaController.php::create_item()`.

### 2. Assert an `mvs_activity` row exists for the private upload
- **Action**: `mysql_query "SELECT id, user_id, media_id, type, privacy FROM wp_mvs_activity WHERE media_id = $PRIVATE_MEDIA_ID"`
- **Expect**: exactly **1 row**, `user_id` = member A's id, `type` = the upload activity type (e.g. `media_upload`). This is the 1.7.0 change — pre-`fc31bf0` this query returned **0 rows**.
- **On fail**: `includes/Social/ActivityService.php` (the `on_upload()` / `log()` path) — the private-media branch is skipping the insert again, reverting `fc31bf0`.

### 3. Confirm the activity row is privacy-gated (matches the media's privacy)
- **Action**: `mysql_query "SELECT a.privacy AS act_privacy, m.privacy AS media_privacy FROM wp_mvs_activity a JOIN wp_mvs_media_index m ON a.media_id = m.media_id WHERE a.media_id = $PRIVATE_MEDIA_ID"`
- **Expect**: the activity row carries the same gating as the media (`media_privacy = private`); the activity row is NOT marked public. The row exists for the owner's history + follow-graph JOIN, not for sitewide broadcast.
- **On fail**: `includes/Social/ActivityService.php` — the row was written but with public/unset privacy, which would leak it into others' feeds.

### 4. Verify the private item is NOT shown sitewide to another member
- **Action**: as member B (`?autologin=<member-b>`), `curl $SITE_URL/wp-json/mvs/v1/feed` and `curl $SITE_URL/wp-json/mvs/v1/users/<member-a-id>/activity`.
- **Expect**: member B's feed and member A's *public* activity view do **NOT** contain `PRIVATE_MEDIA_ID`. The private upload is invisible to a non-owner. (Member A viewing their own activity MAY see it — owner sees own private items.)
- **On fail**: `includes/REST/Controller/ActivityController.php` + `includes/Services/PrivacyService.php::filter_privacy_can_view()` — the feed query is not applying the privacy gate to activity rows.

### 5. Anonymous sees nothing
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/feed` with no auth.
- **Expect**: `PRIVATE_MEDIA_ID` absent from the response.
- **On fail**: same as step 4 — privacy gate missing for logged-out context.

### 6. Contrast: a PUBLIC upload also writes a row (control)
- **Action**: upload a second item with `privacy=public` as member A; capture `PUBLIC_MEDIA_ID`; then `mysql_query "SELECT COUNT(*) FROM wp_mvs_activity WHERE media_id = $PUBLIC_MEDIA_ID"`.
- **Expect**: 1 row (public uploads were always logged — this confirms the fix didn't break the existing public path), AND member B's feed in step 4 WOULD show `PUBLIC_MEDIA_ID`.
- **On fail**: `includes/Social/ActivityService.php` — public path regressed.

### 7. No retroactive back-fill on privacy change (upload-time only)
- **Action**: flip `PRIVATE_MEDIA_ID` to public via `curl -X POST -d '{"privacy":"public"}' -H 'X-WP-Nonce: $NONCE' $SITE_URL/wp-json/mvs/v1/media/$PRIVATE_MEDIA_ID`; re-run the step 2 count.
- **Expect**: still exactly 1 row for `PRIVATE_MEDIA_ID` — the fix is upload-time only, it does not add a second activity row when privacy later changes (no duplicate/back-fill).
- **On fail**: `includes/REST/Controller/MediaController.php::update_item()` is firing an activity log on privacy change.

## Pass criteria

ALL of the following hold:
1. A private upload writes exactly **1** `mvs_activity` row (the 1.7.0 fix; was 0 before `fc31bf0`).
2. That row is privacy-gated to match the media's `private` privacy — not marked public.
3. The private item never appears in another member's `/feed` or in member A's public `/activity` view, and never to anonymous.
4. A public upload also writes exactly 1 row (control path intact) and IS visible in others' feeds.
5. Changing privacy later does not add a second activity row (no retroactive back-fill).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 0 rows for a private upload | Private branch still skips the insert (pre-fix code) | `includes/Social/ActivityService.php` (`on_upload`/`log`) |
| Private item leaks into another member's feed | Activity row not privacy-gated, or feed query ignores gate | `includes/Services/PrivacyService.php`, `includes/REST/Controller/ActivityController.php` |
| Anonymous sees the private item | Logged-out privacy gate missing on `/feed` | `includes/REST/Controller/ActivityController.php` |
| Two rows after a privacy flip | `update_item()` re-logs an activity event | `includes/REST/Controller/MediaController.php::update_item()` |
| Public upload now missing a row | Refactor broke the always-on public path | `includes/Social/ActivityService.php` |

---
journey: album-playlist-and-archive
plugin: wpmediaverse
priority: normal
roles: [subscriber, anonymous]
covers: [MV-ALB-010, MV-ALB-011, MV-ALB-012, playlist-album, album-privacy-lock, album-archive]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Member A has several audio files and at least one image"
  - "Multiple albums with mixed privacy across multiple owners, for the archive step"
estimated_runtime_minutes: 6
---

# Audio playlists via the API, the privacy lock when a member's privacy choice is removed, and the album archive/dashboard split

## Setup

- Member A (`?autologin=<memberA>`), several audio files (`$AUDIO_1`, `$AUDIO_2`), one image (`$IMG_1`).

## Steps

### 1. Create a playlist album via the API (MV-ALB-010)
- **Action**: `POST /wp-json/mvs/v1/albums` with `{"title":"Journey Playlist","type":"playlist","privacy":"public"}` as Member A. Then `POST /albums/{id}/items` with `[$AUDIO_1, $AUDIO_2, $IMG_1]`.
- **Expect**: 201 on both; only `$AUDIO_1`/`$AUDIO_2` join — `mysql_query "SELECT media_id FROM wp_mvs_album_items WHERE album_id=<id>"` does NOT include `$IMG_1`.
- **Action**: `playwright_navigate $SITE_URL/album/journey-playlist/`.
- **Expect**: the album page lists the tracks and plays them in order.
- **Action**: confirm the dashboard's "New album" create modal has no `type` selector at all.
- **Expect**: absence confirmed — this is by owner decision (2026-09-28); do not report it as a defect. Playlists are API/app-only in Free.
- **Action**: create a second playlist with zero items.
- **Expect**: the album page shows the standard empty state, not an error; its privacy still follows the normal album rule (public/members/private/etc., same as any album — MV-ALB-002).

### 2. Album privacy lock when the member's privacy choice is removed (MV-ALB-011)
- **Action**: `wp option update mvs_allow_user_privacy 0`. As Member A (plain subscriber, not admin), attempt to change any of their album's privacy to a DIFFERENT value via `PUT /mvs/v1/albums/{id}`.
- **Expect**: `403 mvs_privacy_locked`.
- **Action**: resubmit the SAME (unchanged) privacy value for that album.
- **Expect**: "succeeds" as a no-op save (200, no actual change) — not refused, since nothing changed.
- **Action**: as an admin (`manage_mvs_settings`), change the same album's privacy to a genuinely different value.
- **Expect**: succeeds — admins are exempt from the lock.
- **Action**: in the browser, open the album edit modal as the locked-out Member A and inspect the privacy select.
- **Expect (verify, don't assume)**: check whether the select is disabled/hidden (ideal) or interactable-then-refused (a UX gap the catalog itself flags as unverified) — report which one actually renders.
- **Action**: restore `mvs_allow_user_privacy` to 1.

### 3. Album archive vs. dashboard Albums tab (MV-ALB-012)
- **Action**: `playwright_navigate $SITE_URL/album/` logged out.
- **Expect**: only PUBLIC albums (from any owner) are listed — a private/members-only album from any member is absent.
- **Action**: as Member A, open the dashboard's Albums tab.
- **Expect**: ALL of Member A's own albums are listed regardless of privacy (private, members, public alike) — this is "my albums," not "public albums." The same shared sort toolbar as Explore is present (Newest/Oldest/Most viewed) plus a count.
- **Action**: sort the dashboard Albums tab by "Oldest".
- **Expect**: order changes accordingly; `mvs_items_per_page` still governs pagination size on both surfaces.

## Pass criteria

ALL of the following hold:
1. A playlist album accepts only audio items via the API; the create modal has no type selector (by design); an empty playlist shows the standard empty state; playlist privacy follows the normal album rule.
2. With `mvs_allow_user_privacy` off: a plain member's genuine privacy CHANGE on their own album is refused (`403 mvs_privacy_locked`); resubmitting the unchanged value succeeds as a no-op; an admin can change it regardless.
3. `/album/` lists only public albums to a visitor; the dashboard Albums tab lists ALL of the current member's own albums regardless of privacy, with the shared sort toolbar.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A non-audio item joins a playlist album | type filter missing on `POST /albums/{id}/items` for `type=playlist` albums | `includes/Services/AlbumService.php` |
| Locked member's privacy change succeeds | `mvs_privacy_locked` check missing or not applied to albums | `includes/REST/Controller/AlbumController.php::update_item()` |
| Admin also blocked by the lock | `manage_mvs_settings` exemption missing | `includes/REST/Controller/AlbumController.php` |
| A private album from another member appears on `/album/` | archive query missing the privacy clause | `templates/cpt-archive.php` |
| Dashboard Albums tab hides the member's own private album | dashboard query incorrectly scoped to public-only | `src/blocks/dashboard-view/view.js`, `includes/REST/Controller/AlbumController.php::get_items()` |

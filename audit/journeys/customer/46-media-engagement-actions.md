---
journey: media-engagement-actions
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-MED-002, MV-MED-004, MV-MED-008, MV-MED-009, view-record, favorite-toggle, share, download]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A public media item with downloads allowed; a second public item with the per-item download toggle off"
estimated_runtime_minutes: 6
---

# A visitor's view is recorded, a member favourites, shares, and downloads a media item

## Setup

- Two public items: `$MEDIA_ID_ALLOWED` (downloads allowed, default), `$MEDIA_ID_BLOCKED` (per-item `allow_download` off via its Edit modal or `mysql_query "UPDATE wp_mvs_media_meta SET meta_value='0' WHERE media_id=$MEDIA_ID_BLOCKED AND meta_key='allow_download'"`).
- Member A (`?autologin=<memberA>`), not the owner of either item.

## Steps

### 1. View is recorded on page load (MV-MED-002)
- **Action**: note the current view count: `mysql_query "SELECT views FROM wp_mvs_media_stats WHERE media_id=$MEDIA_ID_ALLOWED"`. `playwright_navigate $SITE_URL/media/{slug}/` logged out.
- **Expect**: `POST /wp-json/mvs/v1/media/$MEDIA_ID_ALLOWED/view` fires automatically; the count increments by exactly 1; nothing visible changes on the page (no toast) beyond the counter (owner/admin-visible in stats only).
- **Action**: reload the same page 3x within a few seconds.
- **Expect**: the count does NOT increment by 3 — rapid reloads within the dedup window are collapsed (`seen_recently()` window in `MediaController`).

### 2. A denied item never records a view
- **Action**: navigate to a `members`-only item's page while logged out (denied, gated view).
- **Expect**: no `POST .../view` request fires at all (check the network log) — a blocked page load must not still count.

### 3. Favourite is refused while logged out, works when logged in (MV-MED-004)
- **Action**: while logged out, click the favourite icon on `$MEDIA_ID_ALLOWED`.
- **Expect**: toast "Please log in to favorite."; no `POST .../favorite` request sent; icon stays outline.
- **Action**: log in as Member A, click the same icon.
- **Expect**: icon fills immediately (optimistic update); `POST /mvs/v1/media/$MEDIA_ID_ALLOWED/favorite` returns 200/201; `mysql_query "SELECT * FROM wp_mvs_favorites WHERE media_id=$MEDIA_ID_ALLOWED AND user_id=<memberA id>"` returns a row; the item appears in `/my-media/#favorites`.
- **Action**: click the icon again.
- **Expect**: icon reverts to outline; `DELETE /mvs/v1/media/$MEDIA_ID_ALLOWED/favorite` returns 200/204; the DB row is gone; item disappears from the Favorites listing.

### 4. Favouriting a members-only item you cannot view is refused honestly (edge case)
- **Action**: as Member A (not a member of the relevant group/no access), `POST /mvs/v1/media/{privateMediaId}/favorite`.
- **Expect**: `404 mvs_not_found` — identical to favouriting an item that does not exist (no existence leak).

### 5. Share never shows a native `window.prompt()` dialog (MV-MED-008)
- **Action**: click Share on `$MEDIA_ID_ALLOWED` in a browser context where `navigator.share` is unavailable (or stub it out to force the fallback).
- **Expect**: falls back to `navigator.clipboard.writeText()`; a toast confirms the link was copied. Assert in the DOM/JS that no `window.prompt` call occurs — this is a locked regression (removed in 1.2.0 QA).
- **Action**: click Share as a logged-out anonymous visitor on the same public item.
- **Expect**: works without any login gate (permission_callback is `__return_true`); the share count increments: `mysql_query "SELECT shares FROM wp_mvs_media_stats WHERE media_id=$MEDIA_ID_ALLOWED"`.

### 6. Download works, respects per-item and site-wide toggles, and rate-limits (MV-MED-009)
- **Action**: click Download on `$MEDIA_ID_ALLOWED`.
- **Expect**: the file downloads; `mysql_query "SELECT downloads FROM wp_mvs_media_stats WHERE media_id=$MEDIA_ID_ALLOWED"` increments by 1.
- **Action**: click Download on `$MEDIA_ID_BLOCKED`.
- **Expect**: the Download button is not rendered at all for this item (server-side per-item check hides it, not shown-then-error); calling `POST /wp-json/mvs/v1/media/$MEDIA_ID_BLOCKED/download` directly returns 403 `mvs_download_blocked`.
- **Action**: `wp option update mvs_allow_downloads 0`; reload `$MEDIA_ID_ALLOWED`'s page.
- **Expect**: no Download button anywhere on the page; the direct REST call returns 403 `mvs_downloads_disabled`. Restore: `wp option update mvs_allow_downloads 1`.
- **Action**: with downloads re-enabled, call `POST /wp-json/mvs/v1/media/$MEDIA_ID_ALLOWED/download` 31 times within 60 seconds as the same user.
- **Expect**: the 31st call returns 429 (rate-limited at 30/minute/user, `RateLimiter::check('record_download', 30, 60)`).

### 7. Discrepancy: the Media Player block's Download link bypasses both toggles
> **Code note (2026-09-29):** the catalog's "Settings that change it" for MV-MED-009 says the site-wide + per-item toggle govern download everywhere. Reading `src/blocks/media-player/render.php` shows the block's own `showDownload` attribute renders `<a href="$file_url" download>` directly against the signed file URL — it does not check `mvs_allow_downloads` or the item's `allow_download` meta, and it never calls `POST .../download`, so a download through this block neither honors either toggle nor increments the stat. This is narrower than the single-media page's Download control. Confirmed in code, not fixed here — flagged for a follow-up card.
- **Action**: with `mvs_allow_downloads` OFF and/or `$MEDIA_ID_BLOCKED`'s `allow_download` off, place that media in a Media Player block (`[mvs_player]`/block editor) with "Show download" enabled on a page; view the page.
- **Expect (current behavior — regression sentinel for the gap above, not an endorsement)**: the Download link on the player STILL renders and downloads the file, unlike the single-media page. If a future fix makes the player respect both toggles, this step should instead assert the link is hidden — update this journey when that ships.

## Pass criteria

ALL of the following hold:
1. A view is recorded once per page load and deduped on rapid reload; a denied viewer's blocked load records nothing.
2. Favourite is refused with a login toast while logged out; toggles on/off while logged in with a matching DB row; a members-only item you cannot view answers `404` on favourite, not a permission leak.
3. Share never shows `window.prompt()`; works for a logged-out visitor on public content; increments the share stat.
4. Download increments its stat, is hidden (not shown-then-error) when disabled per-item or site-wide, and is rate-limited at 30/minute/user (429 beyond that) — on the single-media page and lightbox. The Media Player block's own Download link is a known, unfixed exception (step 7).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| View count triples on 3 rapid reloads | dedup window (`seen_recently()`) not applied to `view` events | `includes/REST/Controller/MediaController.php::record_view()` |
| Favourite silently no-ops while logged out (no toast) | login-gate toast removed from the favourite action | `src/blocks/media-social/view.js` |
| A `window.prompt()` appears on Share | the removed prompt fallback re-introduced | `src/blocks/media-social/view.js`, `src/blocks/shared-ui/view.js` (share action) |
| Download button shown then errors on a disabled item | server-side render not checking `allow_download` before emitting the button | `templates/media-single.php` |
| Download never rate-limits | `RateLimiter::check('record_download', ...)` removed or misconfigured | `includes/REST/Controller/MediaController.php` |
| Media Player block download ignores both toggles (known gap, step 7) | `src/blocks/media-player/render.php` renders `<a download>` straight to `$file_url` with no `mvs_allow_downloads`/`allow_download` check | `src/blocks/media-player/render.php` |

---
journey: album-privacy-and-pagination
plugin: wpmediaverse
priority: critical
roles: [author, subscriber, anonymous]
covers: [album-privacy-pagination, album-owner-single-view, album-visitor-visibility]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Several published albums exist (mix of authors)"
estimated_runtime_minutes: 6
---

# Album lists count correctly for visitors, and an owner can open their own private album

**Member expectation**: albums by members are visible to visitors, pagination is
honest (the count matches what actually renders), a private album is hidden from
everyone except its owner, and the owner can open their own private album's single
page.

This guards two 2.0.0 fixes:
- `AlbumController::get_items` now filters privacy at the SQL level (LEFT JOIN
  `mvs_media_index` + `explore_privacy_clause`), so `X-WP-Total` reflects only
  viewable albums (Basecamp 10071400189 — visitors hit empty/short pages before).
- `PrivacyService::check_access` resolves album author from `wp_posts.post_author`,
  not the unreliable index `post_author`, so an owner is not denied their own
  non-public album (Basecamp 10071824547).

## Setup

- Owner: member A (`?autologin=<ownerA>`). Create/own a **private** album `$ALBUM_ID`
  with slug `$ALBUM_SLUG` (privacy set through the album create/edit flow, or
  `mysql_query "UPDATE wp_mvs_media_index SET privacy='private' WHERE media_id=$ALBUM_ID"`).
- Note the baseline public album count `$PUBLIC_N` (published albums that are public).

## Steps

### 1. Visitor album list: count matches returned items (no over-report)
- **Action**: `curl -s -D - $SITE_URL/wp-json/mvs/v1/albums?per_page=50 -o /tmp/albums.json` (anonymous).
- **Expect**: the `X-WP-Total` header EQUALS the number of items in the body, and equals `$PUBLIC_N`. The private album `$ALBUM_ID` is NOT in the body.

### 2. Visitor pagination has no empty tail
- **Action**: request the album list with a small `per_page` (e.g. 3) and walk pages 1..N (N = ceil(X-WP-Total/3)).
- **Expect**: every page 1..N-1 is full; the last page is non-empty; no page returns 0 items while `X-WP-Total` implies more. No private album appears on any page.

### 3. Owner sees their own private album in the list, with a matching count
- **Action**: as owner A, `restFetch('albums?per_page=50')`.
- **Expect**: `X-WP-Total` EQUALS returned count; the private album `$ALBUM_ID` IS present.

### 4. A different logged-in member does NOT see the private album
- **Action**: as member B (not owner), `restFetch('albums?per_page=50')`.
- **Expect**: `X-WP-Total` EQUALS returned count; the private album `$ALBUM_ID` is absent.

### 5. Owner opens their own private album single page
- **Action**: `playwright_navigate $SITE_URL/album/$ALBUM_SLUG/?autologin=<ownerA>`.
- **Expect**: the album renders (heading = album title; album grid/empty-state present); NOT "Album not found."

### 6. Non-owner / visitor denied the private album single page
- **Action**: `playwright_navigate $SITE_URL/album/$ALBUM_SLUG/` as member B and as anonymous.
- **Expect**: "Album not found." (branded); album contents never rendered.

## Pass criteria

ALL hold:
1. Visitor album list: `X-WP-Total` == returned count == public album count; private album absent.
2. No empty/short trailing page in visitor pagination.
3. Owner list: count matches, includes own private album.
4. Other member list: count matches, excludes the private album.
5. Owner opens their own private album single page (no "not found").
6. Non-owner + anonymous are denied the private album single page.

## Fail diagnostics

- `X-WP-Total` > returned items for a visitor → `AlbumController::get_items` reverted to post-query `can_view()` filtering with `found_posts` headers; re-apply the `posts_join`/`posts_where` privacy filter via `explore_privacy_clause`.
- Owner denied their own private album (single view) → `PrivacyService::check_access` keying on the index `post_author` again; it must use `wp_posts.post_author` for `mvs_album`/`mvs_collection` (empty `media_type`).
- Private album leaks to a visitor/other member → the SQL privacy clause alias/params, or a missing `mvidx.media_id IS NULL` public fallback.

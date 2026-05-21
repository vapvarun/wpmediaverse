---
journey: signed-url-expires
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [signed-url-ttl, security-token-rotation]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "An existing private media item (id captured as $PRIVATE_ID)"
estimated_runtime_minutes: 3
---

# Signed serve URL expires after configured TTL

**Why this journey exists**: Privacy of private media depends on signed URLs going stale. A TTL set to 0 or a signing-key regression makes URLs permanently valid — defeats privacy. This journey fetches a signed URL, waits past TTL, asserts the same URL fails.

## Setup

- **TTL has a 60-second floor.** `SignedUrlService::get_ttl()` returns `max( 60, $ttl )`, so any value below 60 is clamped to 60. Set TTL to exactly the floor: `update_option('mvs_signed_url_ttl', 60)`. Do NOT use a sub-60 value — it will not shorten expiry and the wait below will never see a 403.
- **Run as a permitted viewer and fetch WITH that session's cookie.** For non-public media `/serve` re-checks `can_view( media_id, current_user )` on every request (the token is not a transferable bearer credential). Run as the owner of `$PRIVATE_ID` OR as a `moderate_mvs_media` user (admin). Every serve fetch below MUST carry the viewer's auth cookie — use the logged-in Playwright session (`fetch(url, { credentials: 'same-origin' })`) or pass the cookie to curl. An anonymous fetch returns 403 by design (NOT expiry) and would make this journey lie.
- Capture an existing private media id as `$PRIVATE_ID`.
- Restore TTL at end.

## Steps

### 1. Fetch signed URL for private media
- **Action**: authenticated GET `$SITE_URL/wp-json/mvs/v1/media/$PRIVATE_ID/signed-url` (viewer's session).
- **Expect**: HTTP 200, capture `URL_A`.

### 2. Verify URL works immediately
- **Action**: authenticated fetch of `URL_A` (browser `fetch(URL_A, {credentials:'same-origin'})` or curl with the session cookie).
- **Expect**: HTTP 200, `Content-Type: image/*`.

### 3. Wait past the TTL floor
- **Action**: `sleep 65` (60s floor + margin).

### 4. Verify URL now expired
- **Action**: authenticated fetch of `URL_A` again (same cookie as step 2).
- **Expect**: HTTP 403 (token expired). Because the viewer's `can_view` still passes, a 403 here can only be the expired token — which is exactly what we are asserting.

### 5. Fetch fresh URL
- **Action**: authenticated GET `$SITE_URL/wp-json/mvs/v1/media/$PRIVATE_ID/signed-url`.
- **Expect**: HTTP 200, `URL_B != URL_A`.

### 6. URL_B works
- **Action**: authenticated fetch of `URL_B`.
- **Expect**: HTTP 200.

### 7. Cleanup
- **Action**: `wp option update mvs_signed_url_ttl 60`

## Pass criteria

With the viewer's session attached: URL_A streams initially (200), expires after the 60s floor (403); URL_B is different and works.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| URL_A still works after the wait | TTL not honored or token has no exp claim | `includes/Services/SignedUrlService.php` `verify_token()` / `get_ttl()` |
| URL_B == URL_A | Signing payload missing nonce/timestamp | `includes/Services/SignedUrlService.php` `sign_url()` |
| 403 *immediately* in step 2 | Fetch sent without the viewer's cookie, OR viewer lacks `can_view` for `$PRIVATE_ID` | test harness (attach cookie / pick a viewable item), not the product |

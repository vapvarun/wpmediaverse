---
journey: signed-url-expires
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [signed-url-ttl, security-token-rotation]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "An existing private media item (id captured as $PRIVATE_ID)"
estimated_runtime_minutes: 4
---

# Signed serve URL expires after configured TTL

**Why this journey exists**: Privacy of private media depends on signed URLs going stale. A TTL set to 0 or a signing-key regression makes URLs permanently valid — defeats privacy. This journey fetches a signed URL, waits past TTL, asserts the same URL fails.

## Setup

- Set TTL temporarily to 5 seconds: `update_option('mvs_signed_url_ttl', 5)`.
- Restore TTL to default at end (60 seconds).

## Steps

### 1. Fetch signed URL for private media
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/media/$PRIVATE_ID/signed-url`
- **Expect**: HTTP 200, capture `URL_A`.

### 2. Verify URL works immediately
- **Action**: `curl -I "$URL_A"`
- **Expect**: HTTP 200, `Content-Type: image/*`.

### 3. Wait 6 seconds (1 second past TTL)
- **Action**: `sleep 6`

### 4. Verify URL now expired
- **Action**: `curl -I "$URL_A"`
- **Expect**: HTTP 403 (token expired).

### 5. Fetch fresh URL
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/media/$PRIVATE_ID/signed-url`
- **Expect**: HTTP 200, `URL_B != URL_A`.

### 6. URL_B works
- **Action**: `curl -I "$URL_B"`
- **Expect**: HTTP 200.

### 7. Cleanup
- **Action**: `wp option update mvs_signed_url_ttl 60`

## Pass criteria

URL_A streams initially (200), expires after 6s (403); URL_B is different and works.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| URL_A still works after sleep | TTL not honored or token has no exp claim | `includes/Services/SignedUrlService.php` `verify_token()` |
| URL_B == URL_A | Signing payload missing nonce/timestamp | `includes/Services/SignedUrlService.php` `sign_url()` |

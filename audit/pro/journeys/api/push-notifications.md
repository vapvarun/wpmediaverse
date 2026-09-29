---
journey: push-notifications
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber]
covers: [MV-PSH-001, MV-PSH-002, MV-PSH-003, MV-PSH-004, MV-PSH-005, MV-PSH-006, MV-PSH-007, push-notifications, expo-delivery, device-tokens]
prerequisites:
  - "Both plugins active"
  - "Two member accounts (A, B) to exercise the cross-registry case"
  - "An Expo-format test token; ability to trigger a notification (e.g. a follow)"
estimated_runtime_minutes: 10
---

# Push writes into Free's ONE device-token table; delivery and the in-app notification always say the same thing; a hijack attempt is refused, not moved

**Why this journey exists**: `wp_mvs_device_tokens` is the single push device registry the Free CLAUDE.md documents — Pro's `/mvs-pro/v1/push/register-device` writes there too, and Pro's legacy `mvs_pro_push_devices` table was emptied by Migrator v18 and must stay empty. This journey proves the registry is genuinely shared, the anti-hijack design, and that push delivery never drifts from the in-app notification it mirrors.

## Setup

- Site: `$SITE_URL`; Member A `?autologin=<A>`, Member B `?autologin=<B>`.

## Steps

### 1. Register a device — lands in Free's table, not Pro's legacy one
- **Action**: as Member A, `POST /wp-json/mvs-pro/v1/push/register-device {expo_push_token, platform}`.
- **Expect**: `{ "registered": true }`. Row lands in `wp_mvs_device_tokens`; confirm `wp_mvs_pro_push_devices` (legacy, emptied by Migrator v18) stays empty/untouched.
- **Also**: empty token → 400 `mvs_invalid_token`; not logged in → 401 `mvs_unauthorized`.

### 2. Re-registering the same token for the same user upserts, no duplicate
- **Action**: register the same token for Member A a second time.
- **Expect**: no duplicate row (`ON DUPLICATE KEY UPDATE`), no error.

### 3. Cross-registry hijack attempt is refused
- **Action**: Member A registers token T; Member B attempts to register the SAME token T.
- **Expect**: `register_token()` returns false when the token exists under a different `user_id`; response is still 200 `{ "registered": false }`, not an error status — the app must check the boolean, not just HTTP status.

### 4. Push delivery mirrors the in-app notification exactly
- **Action**: trigger a notification for Member A (e.g. Member B follows them) with A holding a registered Expo token; observe the AS action `mvs_pro_push_send` queue then run; inspect the Expo API payload.
- **Expect**: `title` = site name, `body` = the SAME message text as the in-app notification (both built once, upstream, by Free's `NotificationService`), `data.type` in {follow, conversation, media} with a deep-link id. Messages are batched in chunks of up to 100.

### 5. Delivery falls back inline if Action Scheduler is unavailable
- **Action**: (if reproducible) simulate AS unavailability; trigger a notification.
- **Expect**: delivery runs INLINE on the same request instead of silently dropping — worth timing, since a slow Expo call would then add latency to whatever triggered the notification.

### 6. Zero-token recipient is a clean no-op
- **Action**: trigger a notification for a user with no registered tokens.
- **Expect**: nothing queued, no error.

### 7. Non-Expo-format tokens are filtered before ever reaching Expo
- **Action**: seed a raw FCM/APNs-format token (not `ExponentPushToken[...]`/`ExpoPushToken[...]`) for a user; trigger a notification.
- **Expect**: filtered out by the Expo-token-format check, never sent to Expo.

### 8. Invalid/expired token pruning is narrowly scoped
- **Action**: register one valid token and one stale token that Expo will report `DeviceNotRegistered` for; trigger a push; inspect the pruning behavior.
- **Expect**: ONLY the token Expo explicitly reports `status: error, details.error: DeviceNotRegistered` is removed via `unregister_device()`; any other Expo error status (rate limit, malformed request) leaves the token alone.

### 9. A network failure reaching Expo skips pruning entirely
- **Action**: (if reproducible) simulate `wp_remote_post` returning a `WP_Error` when reaching Expo.
- **Expect**: pruning is skipped entirely — fails safe, never misinterprets a network error as "device gone."

### 10. Per-type mute preference suppresses Pro delivery automatically
- **Action**: hook `mvs_push_should_send` to return false for a specific notification type; trigger that type for a user with valid tokens.
- **Expect**: `mvs_push_send` never fires, so Pro's `on_push_send()` never runs — Free withholds the hook entirely when muted. (Note: base install ships no UI to mute types; this is an extension seam only — do not expect a member-facing mute control to exist.)

### 11. Unregister a device — scoped, no information leak
- **Action**: `DELETE /wp-json/mvs-pro/v1/push/register-device` with Member A's own token; then with a token belonging to Member B.
- **Expect**: own token → `{ "removed": true }`, row gone from `wp_mvs_device_tokens`. Someone else's token → `{ "removed": false }` — no confirmation the token exists at all.

### 12. App branding settings surface via the public `/app/config`
- **Action**: set Accent Color, App Logo, Login Background, "Default to Dark Mode" in Settings > App; save; call `GET /wp-json/mvs/v1/app/config` unauthenticated.
- **Expect**: `branding` object gains `accent_color`/`logo_url`/`login_bg_url` only for fields actually set — an empty accent color is OMITTED entirely (never sent as `""`), so the app falls back to its own default rather than rendering an invalid color.

### 13. A deleted logo/background attachment omits the field, not a broken URL
- **Action**: point the Logo or Login Background setting at a since-deleted attachment; call `/app/config`.
- **Expect**: `wp_get_attachment_image_url()` returns false; the field is silently omitted from the branding payload.

## Pass criteria

1. Registration writes into Free's shared table only, upserts on re-registration, and refuses (not moves) a hijack attempt with a boolean-only response.
2. Push body/title/deep-link always match the in-app notification exactly, delivered via AS with an inline fallback.
3. Pruning removes only explicitly-`DeviceNotRegistered` tokens and skips entirely on a network failure.
4. Mute suppression works via the documented filter (with no false claim of a UI that doesn't exist).
5. Unregister is scoped per-owner with no existence leak.
6. `/app/config` branding correctly omits unset/broken fields rather than sending empty/invalid values.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Token hijack succeeds (moves ownership) | `register_token()` missing the different-owner check | Free `PushService::register_token()` |
| Push body differs from the in-app notification | Pro building its own message text instead of reusing Free's | Pro `PushService::on_push_send()` |
| A valid token gets pruned on a rate-limit error | pruning matching on any error status instead of exactly `DeviceNotRegistered` | `PushService::prune_invalid_tokens()` |
| `/app/config` sends an empty accent_color string | field-omission guard missing | app-config branding contributor |

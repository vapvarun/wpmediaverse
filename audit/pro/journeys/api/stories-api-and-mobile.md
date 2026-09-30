---
journey: stories-api-and-mobile
plugin: wpmediaverse-pro
priority: high
roles: [anonymous, member]
covers: [MV-STY-012, MV-STY-013, MV-STY-014, stories, api-contract, license-does-not-gate-features]
prerequisites:
  - "Both plugins active"
  - "WP-CLI available for toggle/deactivation/license manipulation"
estimated_runtime_minutes: 8
---

# The Stories REST surface has correct permission checks, the mobile flag is layout-independent, and every off/unlicensed state is clean

**Why this journey exists**: this is the contract-level pass for the Stories
REST routes plus the three "is it really off" states that matter for support —
toggle off, Pro deactivated, and Pro active with an invalid/expired license.
Each state must resolve to either fully working or cleanly absent, never a
visible-but-non-functional shell — and the license state specifically must
NEVER gate this (or any) feature per this plugin's explicit design rule.

## Setup

- Site: `$SITE_URL`; two members (owner, non-owner); one media item eligible to become a story.
- `mvs_stories_enabled = 1` for the API-contract steps.

## Steps

### 1. `GET /stories` public, needs a scope
- **Action**: `curl "$SITE_URL/wp-json/mvs-pro/v1/stories"` (no `author_id`), logged out.
- **Expect**: empty result — public callback, but the default "network" scope needs a viewer with a network, so an anonymous caller with no scope sees nothing.

### 2. `GET /stories?author_id=<id>` — one person's public stories, logged out
- **Action**: `curl ".../stories?author_id=<id>"` logged out.
- **Expect**: 200, paginated (`X-WP-Total`/`X-WP-TotalPages`), each item has `media_id`, `media_type`, `thumbnail_url`, `expires_at`, `viewed` (viewer-relative), nested `author`.

### 3. `POST /media/{id}/story` across three personas
- **Action**: as the media's owner; as a different logged-in member; logged out.
- **Expect**: owner succeeds; different member gets 403 "You can only manage your own stories."; logged out gets 401.

### 4. `DELETE /media/{id}/story` across the same three personas
- **Action**: repeat step 3's personas against DELETE.
- **Expect**: same pattern — owner succeeds, non-owner 403, logged-out 401. For anyone who cannot see the media at all (not just isn't the owner), refusal is 404, never 403 — privacy-preserving.

### 5. `POST /stories/{id}/view` respects visibility, never records the author's own view
- **Action**: as a viewer who CAN see the media; as one who CANNOT; as the author on their own story.
- **Expect**: viewer-who-can-see gets `{recorded: true}`; viewer-who-cannot gets 404; author's own call never records (`recorded` reflects that, or the count doesn't move).

### 6. Every refusal is a real `WP_Error`, never a 200 with `success:false`
- **Action**: inspect every non-2xx response above.
- **Expect**: proper HTTP status + `WP_Error` code/message — this plugin's own coding rule against refusal-as-success holds here too.

### 7. `duration_hours` is clamped, never rejected
- **Action**: `POST /media/{id}/story` (or the create path) with `duration_hours: 0` and again with `9999`.
- **Expect**: both succeed with the value clamped into `[1,168]`, never a validation error.

### 8. Toggle OFF — routes genuinely absent, not registered-but-refused
- **Action**: `wp option update mvs_stories_enabled 0`; hit every route in steps 1-5.
- **Expect**: every URL returns a clean 404 unknown-route (`rest_no_route`) — `register_rest_route()` for these was never called, not a 403 from a live but gated route.
- **On fail**: `includes/Stories/*Controller` registration — check it's gated at construction, not per-request.

### 9. Pro deactivated — the Free-side checkbox specifically hides, no broken shell
- **Action**: with `mvs_stories_enabled` still `1` in the DB from before, deactivate Pro entirely; check the upload checkbox and the stories bar block on the frontend.
- **Expect**: no upload checkbox (Free's `stories_available()` checks `defined('MVS_PRO_VERSION')`); bar block renders nothing — never a broken half-shell.

### 10. Invalid/expired license — Stories works completely normally
- **Action**: reactivate Pro; set an intentionally invalid/expired license key; leave `mvs_stories_enabled = 1`.
- **Expect**: Stories works exactly as when licensed — posting, viewing, admin page, REST routes all fully functional. Only the license status badge in Settings shows "Inactive"; the plugin's auto-update channel is the only thing affected.
- **On fail**: any Stories code path that checks license state directly — per this plugin's design rule, license must never gate a feature (see `audit/pro/journeys/admin/license-management.md` for the general license contract).

### 11. Mobile app feature flag is driven purely by the toggle, not Layout
- **Action**: set Layout to grid (non-Instagram) with Stories on; `curl "$SITE_URL/wp-json/mvs/v1/app/config"`.
- **Expect**: `features.stories` is `true`, driven purely by `mvs_stories_enabled`, with no reference to the Layout setting anywhere in that response's derivation.
- **On fail**: whatever builds `/app/config`'s `features.stories` key.

### 12. Re-enabling after being off honors pre-existing, still-unexpired story meta
- **Action**: with an active (unexpired) story, toggle Stories off then back on within its 24h window.
- **Expect**: the story reappears immediately once re-enabled — the toggle never deletes story meta, only stops new writes/registration while off.

## Pass criteria

1. Every REST route's permission pattern matches: owner succeeds, non-owner-who-can-see gets 403, anyone-who-cannot-see (logged in or not) gets a privacy-preserving refusal indistinguishable by shape.
2. No refusal anywhere is a 200 with `success:false`.
3. `duration_hours` is clamped, never rejected.
4. Toggle off makes every route a clean unregistered 404; Pro-deactivated hides the Free-side checkbox with no broken shell; an invalid/expired license changes NOTHING about Stories functionality.
5. The mobile `/app/config` flag is Layout-independent.
6. Toggling off then back on within the expiry window loses no data.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A route stays live (403 instead of 404) while the toggle is off | registration not gated at construction time | `includes/Core/Plugin.php` / Stories controller registration |
| Non-owner-who-can't-see gets 403 instead of 404 | branch order in the permission callback | Stories REST controller |
| `/app/config` `features.stories` follows Layout | flag derivation reads the wrong option | wherever `/app/config` is assembled |
| License state changes Stories behavior | a license check leaked into a Stories code path | grep `mvs_pro_license`/license helper calls under `includes/Stories/` |
| Free-side checkbox still shows with Pro deactivated | `stories_available()` missing the `MVS_PRO_VERSION` check | Free's upload-form partial |

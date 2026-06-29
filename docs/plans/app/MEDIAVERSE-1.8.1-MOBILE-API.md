# WPMediaVerse 1.8.1 — Mobile API Contract

Status: **planned** (branch `1.8.1`, both free + pro). Derived from the `wbcom-mobile-app` skill's readiness audit of 1.8.0.
Consumer: the WPMediaVerse mobile app (Expo/React Native, standalone) — and later a BuddyNext module. Built against `mvs/v1` (+ `mvs-pro/v1`) only. **No BuddyPress dependency.**

## Why this exists

The 1.8.0 readiness audit found WPMediaVerse already mobile-ready on the fundamentals — Application-Passwords auth, a paginated `/feed`, server-side pagination everywhere, and the full native social graph (follows, DMs, notifications, reactions, comments, favorites, albums, profiles). Only **four contract deltas** remain before an app can ship. This doc specs exactly those four. Nothing structural changes.

## Authentication (no work — already correct)

The app authenticates with **WordPress core Application Passwords** (`Authorization: Basic`), exactly as Jetonomy. Every controller extends `WP_REST_Controller` with `permission_callback` using `is_user_logged_in()` / `current_user_can()`, so this works today. **No JWT, no custom token.** The new `mvs/v1/auth/*` group (added in 1.8.0) is not the mobile path and is left as-is. Social login (Apple/Google/Facebook) is BuddyNext-only future scope — not in 1.8.1.

---

## Item 1 — `GET /mvs/v1/app/config` (free, public) — ABSENT → ADD

White-label branding + feature flags, pre-login. The single endpoint the app reads before theming itself and deciding which surfaces to mount.

```json
{
  "accent_color": "#...", "logo_url": "...", "login_bg_url": "...",
  "dark_mode_default": false,
  "pro_active": true,
  "features": {
    "messaging": true, "reactions": true, "comments": true, "albums": true,
    "battles": true, "challenges": false, "tournaments": false,
    "boosts": true, "streaks": true, "groups": true, "video": true
  }
}
```

- Branding falls back to Pro white-label settings, else free core settings. Do NOT restate site name/description/icon — those come from the core `/wp-json/` index.
- `features.*` is the **union of free + active Pro toggles.** The Pro toggles already exist as options (`mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, streaks, groups, video) — this endpoint just reads and exposes them. Implement via a `mvs_app_config_features` filter the free plugin defines and Pro filters into, so it stays additive.
- Public read (pre-login theming). No auth required.

**Acceptance:** app fetches config unauthenticated; disabling a Pro toggle removes its key/flips it false; app hides that surface with no probing of individual endpoints.

---

## Item 2 — `POST` + `DELETE /mvs/v1/push/register-device` (pro) — ABSENT → ADD

Native push. Today notifications are poll-only (`GET /me/notifications`). For a likes/comments/follows/DM app, push is the engagement backbone.

- Store the Expo token: `{ expo_push_token, platform: "ios"|"android", device_name? }` in a new `mvs_push_devices` table (UNIQUE on token so it migrates between accounts on re-login). Login-required.
- **Fan-out:** the existing `NotificationService` already fires on every notifiable event (reaction, comment, mention, follow, DM, moderation). Bind a sender to those events that, in addition to the in-app row, POSTs to `https://exp.host/--/api/v2/push/send` for the user's registered tokens — async (Action Scheduler, which the plugin already uses).
- **Deep-link payload:** `{ type: "media"|"comment"|"reaction"|"follow"|"conversation", id: int }` so a tapped push routes to the right screen.
- Verify the notification-event hooks pass enough args to build the payload (Jetonomy precedent: a hook fired more args than the handler accepted and silently bailed — check the accepted-args count).

**Acceptance:** registering a device then triggering a reaction/comment/DM delivers an Expo push with a deep-link payload. (Verify on a real/preview build — native push does not work on the Expo dev client.)

---

## Item 3 — Viewer-interaction fields on media reads (free) — PARTIAL → COMPLETE

`is_following` is already embedded (`FollowController`, `UserController`, 1.8.0). Still missing on **media** read payloads:

- `viewer_reaction` — string|null, the current user's reaction type on this media (`like|love|haha|wow|sad|angry` or null)
- `is_favorited` — bool, current user has favorited this media

Add both in `MediaController::prepare_item_for_response()` (alongside existing `is_owner` / `can_view`). Additive, backward-compatible.

**Big-site:** batch-fetch per page — one query for the viewer's reactions across the page's media IDs, one for favorites — hydrate from a set. Never per-item in the loop. If batch is deferred, record a TODO in the commit body per the big-site checklist.

**Acceptance:** the `/feed` and `/media` payloads carry the viewer's reaction + favorite state in one round-trip; a 2000-item feed adds no per-row queries.

---

## Item 4 — Write-time block enforcement in REST permission callbacks (free) — HARDEN

Blocks/privacy are enforced at the **service layer** today: a blocked relationship filters *reads* (empty list) but writes are not gated at the permission callback — a blocked user can still `200` on a write attempt. Because Application Passwords are minted by core, the gate must live in the REST permission callback.

- In the `create_item_permissions_check()` of comments, reactions, DMs (`/conversations/{id}/messages`), and follow, call the existing `BlockService` and return `403` when the actor is blocked by (or has blocked) the target.
- If a site-level suspend/ban exists, enforce it on all writes too (parity with Jetonomy's ban gate).
- **Ship a contract test:** a user blocked by the target gets `403` on POST comment / reaction / DM to that target's content.

**Acceptance:** automated test proves a blocked actor is `403` on every write path, not `200`.

---

## Out of scope for 1.8.1

- **Stories** — returns in plugin 1.3 line; the app's stories surface waits on it.
- **Admin surfaces** — moderation queue, analytics, quota admin: desktop, not in the v1 member app.
- **Gamification authoring** — view + vote (battles/challenges/tournaments) are in-app; creating contests stays desktop unless scoped later.
- **White-label / BuddyNext packaging** — handled at the app build-profile layer (see the skill's `release-and-build-profiles.md`), not the plugin contract.

## Keep in sync

Add the new routes (`/app/config`, `/push/register-device`) + the `mvs_push_devices` table + the `mvs_app_config_features` filter to `audit/manifest.json` (free + pro) in the same change.

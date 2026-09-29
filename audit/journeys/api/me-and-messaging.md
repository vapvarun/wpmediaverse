---
journey: api-me-and-messaging
plugin: wpmediaverse
priority: critical
roles: [anonymous, member, participant, non-participant]
covers: [MV-API-015, MV-API-016, me-scoped-to-caller, messaging-hard-off-404, conversation-existence-masking]
prerequisites:
  - "Site reachable at $SITE_URL, mvs/v1 namespace registered"
  - "A logged-in member with some history in each /me/* area (media, favorites, transactions, notifications, devices)"
  - "Two members for the messaging pass; MV-SET-020 (messaging on/off) and MV-SET-021 (who can start a conversation) toggleable"
estimated_runtime_minutes: 12
---

# Every /me/* route answers only for the caller, and messaging is a hard 404 kill-switch, not a soft 403, when disabled

**Why this journey exists**: the `/me/*` surface is spread across SEVEN
controllers (`ProfileController`, `AccountController`, `DeviceController`,
`TransactionController`, `InterestsController`, `FavoriteController`,
`ReportController`, `UserController`, `NotificationController`) and must never
accept a `user_id` parameter that redirects it to someone else's data — that
would be a serious authorization bug, not a privacy nuance. Separately,
messaging's "OFF" state must be genuinely invisible (route not registered,
404) rather than merely refused (403), because an app needs to tell "this
isn't a thing here" apart from "you can't do that right now". Grounded against
`includes/Messaging/MessagingController.php`, `includes/Core/Plugin.php`
(`messaging_enabled()`/`init_messaging()`), and the `/me/*` route
registrations across the controllers above.

## Setup

- A logged-in member with at least one item in each of: media, favorites,
  transactions (even if zero-activity), notifications, a registered device
  token.
- Two members for messaging: participant and non-participant scenarios.

## Steps

### 1. MV-API-015 — Me: profile, media, stats, storage, favorites, blocked, interests, transactions, notifications, avatar, deletion, devices
- **Action**: unauthenticated, hit every route in this group: `GET/PUT
  /me/profile`, `GET /me/media`, `/stats`, `/storage`, `/favorites`,
  `/blocked`, `/interests`, `/transactions`, `/notifications`,
  `/notifications/count`, `POST /me/notifications/read`, `POST/DELETE
  /me/avatar`, `GET/DELETE /me/deletion`, `POST/DELETE /me/devices`.
- **Assert**: every single one returns `401` — no data leak, since there is
  no "me" to answer for. This spans multiple controllers
  (`check_logged_in`/`logged_in_check`/`auth_check` — different method names,
  same effect), so check each controller's guard independently rather than
  assuming one covers all.
- **Action**: as the logged-in member, `POST /me/avatar` then `DELETE
  /me/avatar`.
- **Assert**: avatar upload succeeds; delete falls back to Gravatar (no
  broken image state).
- **Action**: `GET /me/transactions` on an account with zero activity; then
  attempt to find any PUT/edit route on a past transaction entry.
- **Assert**: zero-activity returns an empty list cleanly, not an error.
  There is NO edit route on `TransactionController` — the ledger is
  append-only, running-balance; only new events append (confirm by grepping
  the controller's `register_routes()` for anything beyond the single
  `GET /me/transactions` registration).
- **Action**: `GET /me/storage` and cross-check against MV-SET-001's site
  limit (or a per-user override from MV-ADM-016 if one exists for this
  account).
- **Assert**: the numbers match the setting's math exactly.
- **Action**: as member X, attempt to pass another user's ID as a parameter
  to any `/me/*` route (e.g. `?user_id={other-id}` or similar) if the route
  accepts any ID-shaped param at all.
- **Assert**: every route is hard-scoped to `wp_get_current_user()` server-side
  — no such parameter can redirect the response to someone else's data. This
  is the single most important assertion in this block; a failure here is a
  serious authorization bug, not a UX nit.

### 2. MV-API-016 — Conversations and messages
- **Action**: with MV-SET-020 (messaging) OFF, hit every route in this group
  (`/conversations`, `/conversations/{id}`, `.../messages`, `/messages/{id}`,
  `/messages/poll`, `/me/conversations`, `/me/messages/unread-count`, etc.).
- **Assert**: EVERY route 404s — confirmed in code: `init_messaging()` checks
  `messaging_enabled()` before calling `MessagingController::register_routes()`
  at all, so the routes are never registered, not merely gated by a
  permission check. This is the 404-not-403 "feature doesn't exist here"
  signal an app needs.
- **Action**: with messaging ON and MV-SET-021 set restrictively, attempt a
  disallowed new-conversation (`POST /conversations`) from a member who
  doesn't qualify.
- **Assert**: refused with a specific reason (not a generic 403) — this is
  the OTHER shape, "feature exists, this action doesn't" (403, feature on).
- **Action**: `POST /messages/upload` with a file over 10MB; with a PDF
  (renamed to an image extension); with a real oversized-but-renamed file to
  test MIME sniffing.
- **Assert**: 10MB cap enforced; MIME is checked via `finfo_file()` (actual
  file content), not the extension — a renamed PDF is still refused even
  with an image extension. PDFs are excluded from message attachments
  entirely, same as the media library restriction.
- **Action**: `DELETE /messages/{id}/unsend` on a message both participants
  can see; separately, a plain `DELETE /messages/{id}`.
- **Assert**: unsend removes the message for ALL participants; plain delete
  is a distinct, presumably per-user-only removal — confirm the two produce
  visibly different states for the OTHER participant (unsend removes it for
  them too, delete alone should not).
- **Action**: as a non-participant (guessing a real conversation ID), `GET`,
  `PATCH`, and `DELETE /conversations/{id}`.
- **Assert**: ALL return the SAME `404 not_found` for both "doesn't exist"
  and "exists but you're not a participant" — conversations use the
  EXISTENCE-MASKING pattern consistently, including on destructive routes
  like DELETE, which is notably STRICTER than media/album writes (those use
  explicit-403 for wrong-owner). Do not assume the media pattern applies
  here — verify DELETE specifically, since that's the route most likely to
  regress toward media's explicit-403 shape.
- **Action**: `GET /messages/poll` as a client without a push/websocket
  layer.
- **Assert**: works as the documented fallback transport
  (`RestPollingTransport`).

## Pass criteria

1. Every `/me/*` route, unauthenticated, returns 401 with zero data leaked.
2. No `/me/*` route can be redirected to another user's data via any request
   parameter.
3. The transactions ledger has no edit/update route — append-only, verified
   by absence, not by a rejected attempt.
4. With messaging OFF, every conversation/message route 404s (route
   genuinely unregistered), not 403.
5. Every conversation-scoped route, including DELETE, masks existence
   identically for "doesn't exist" vs. "not a participant".
6. Message attachment MIME is checked by content (`finfo_file()`), not
   extension; PDFs are excluded even here.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A `/me/*` route leaks another user's data via a parameter | route handler reading a request param instead of `wp_get_current_user()` | the specific controller (`ProfileController.php`, `AccountController.php`, etc.) |
| Messaging OFF still returns 403 instead of 404 on conversation routes | `messaging_enabled()` check removed from `init_messaging()`, routes registered unconditionally | `includes/Core/Plugin.php::init_messaging`, `Plugin::messaging_enabled` |
| `DELETE /conversations/{id}` as a non-participant returns 403 instead of 404 | conversation controller drifted toward media's explicit-403 pattern | `includes/Messaging/MessagingController.php` |
| A renamed PDF passes as a message attachment | MIME check relying on extension instead of `finfo_file()` | `includes/Messaging/MessagingController.php` upload handler |
| `unsend` behaves identically to plain `delete` | unsend not distinctly implemented, just aliased to delete | `includes/Messaging/MessagingService.php` |

---
journey: api-moderation-and-social
plugin: wpmediaverse
priority: critical
roles: [anonymous, member, moderator, blocked-member]
covers: [MV-API-013, MV-API-014, moderation-queue-not-public, block-hides-content, block-exempt-report]
prerequisites:
  - "Site reachable at $SITE_URL, mvs/v1 namespace registered"
  - "A moderator account (moderate_mvs_media) and a plain-member account, media items pending review"
  - "Two members A and B where A has blocked B"
estimated_runtime_minutes: 10
---

# Moderation queue reads stay capability-gated; a block actually hides content on the read side while report/unblock stay exempt

**Why this journey exists**: unlike almost every other list route in this
plugin, the moderation queue's READ is never public — even counts. Separately,
the 2.5.0 block fix made a block hide content on both read and Explore's
listing (page 1 included), while explicitly keeping report/unblock/unfollow
exempt so a blocked member can always withdraw consent or report their
blocker. Both properties are easy to regress independently. Grounded against
`includes/REST/Controller/ModerationController.php`,
`includes/REST/Controller/UserController.php`,
`includes/REST/Controller/FollowController.php`,
`includes/REST/Controller/ReportController.php`.

## Setup

- Moderator account, plain-member account, several media items pending
  review.
- Member A blocks member B (`POST /mvs/v1/users/{B}/block` as A).

## Steps

### 1. MV-API-013 — Moderation: queue, counts, analyze/approve/reject
- **Action**: as a plain member, `GET /mvs/v1/moderation` and `GET
  /mvs/v1/moderation/counts`.
- **Assert**: `403 mvs_rest_forbidden` ("You do not have permission to
  moderate media.") on BOTH — `moderate_permissions_check` gates every route
  in this controller including the read-only list/counts, unlike most read
  routes in the plugin which default to `__return_true`.
- **Action**: as the moderator, `POST /mvs/v1/moderation/{id}/approve` (or
  `/reject`) on a pending item.
- **Assert**: produces an identical resulting state to MV-ADM-013's
  admin-UI moderation action (same underlying `ModerationService` call).
- **Action**: two moderator sessions approve the SAME item within seconds of
  each other.
- **Assert**: the second approve gets a clean "already handled" outcome —
  not a duplicate action, not a 500 (multi-actor concurrency).

### 2. MV-API-014 — Users: profile/media/activity/followers/block/follow/report
- **Action**: as B (blocked by A), `GET /mvs/v1/users/{A}/media`, `GET
  /users/{A}` and `GET /users/{A}/followers`.
- **Assert**: A's media, profile listing and counts are HIDDEN from B on the
  read side (2.5.0 fix — confirm this holds on page 1 of any paginated
  listing, not only later pages, since the original bug was page-1-only
  visible).
- **Action**: as B, attempt to follow or message A (`POST
  /mvs/v1/users/{A}/follow`, and a messaging attempt if MV-SET-020 is on).
- **Assert**: refused via the write-side block gate (`RestGuards`) — a block
  is bidirectional on the write side even though A is the one who blocked.
- **Action**: as B, `POST /mvs/v1/users/{A}/report`.
- **Assert**: this SUCCEEDS despite A having blocked B — `RestGate`
  explicitly classifies report (and unblock/unfollow) as exempt from the
  block gate, so a blocked member can always withdraw consent or report
  their blocker.
- **Action**: as A, `PUT` on B's admin-only user fields (if any endpoint
  exposes this) without `edit_users`.
- **Assert**: refused — `manage_users_check` requires `edit_users`
  specifically, separate from the ordinary logged-in gate the rest of this
  controller uses.
- **Assert (admin-surface gap)**: confirm there is no wp-admin screen to
  view/audit/undo A's block of B — the API is the ONLY interface for block
  state, a documented limit (CAPABILITIES.md), not a bug to file.

## Pass criteria

1. Every route in `ModerationController` for THIS group — including
   read-only list/counts — refuses a non-`moderate_mvs_media` caller; none
   default to public.
2. Two concurrent approve/reject calls on the same item never duplicate the
   action or error; the second is a clean no-op.
3. A blocked member cannot see the blocker's profile/media/followers content
   (page 1 included) and cannot follow/message them.
4. Report, unblock and unfollow remain exempt from the block gate in both
   directions.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Plain member can read moderation counts/list | `moderate_permissions_check` missing from a route registration | `includes/REST/Controller/ModerationController.php::register_routes` |
| Blocked member still sees blocker's content on page 1 | block filter applied to page >1 only, or applied after pagination | `includes/REST/Controller/UserController.php`, `includes/Social/*Service.php` block-filter logic |
| A blocked member cannot report their blocker | `RestGate`'s exempt-action list missing `report`/`unblock`/`unfollow` | `includes/REST/RestGate.php` |
| `GET /ai/usage` returns data to a plain member | `settings_permissions_check` weakened or bypassed | `includes/REST/Controller/ModerationController.php` |
| Concurrent approve produces a duplicate moderation-log row | `ModerationService` not idempotent on already-approved state | `includes/Services/ModerationService.php` |

**Note on catalog drift**: MV-API-020 ("AI usage") is covered in
`api/auth-app-feed-serve.md`, not this file, per the manifest assignment —
but its route (`GET /mvs/v1/ai/usage`) is actually registered inside
`ModerationController::register_routes()` (this file's controller), not a
dedicated AI/transactions controller. See that file's own drift note.


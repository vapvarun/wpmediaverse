---
journey: api-auth-app-feed-serve
plugin: wpmediaverse
priority: critical
roles: [anonymous, member, administrator]
covers: [MV-API-017, MV-API-018, MV-API-019, MV-API-020, MV-API-021, MV-API-022, app-config-exempt-from-members-gate, serve-live-permission-recheck]
prerequisites:
  - "Site reachable at $SITE_URL, mvs/v1 namespace registered"
  - "A member's real username/email + password; a suspended member (MV-ADM-015); MV-SET-015 (App Sign-In) toggleable"
  - "A private media item and a current signed URL for it (MV-API-007)"
  - "Media/activity from multiple members with mixed privacy, and a follow graph, for the feed step"
estimated_runtime_minutes: 14
---

# Pre-login discovery, credential exchange, the feed, and signed media delivery all stay correctly exempt (or NOT exempt) from the Members Only gate

**Why this journey exists**: `/app/config`, `/auth/app-password` and `/serve`
are the three routes explicitly Rule-2-allowlisted to bypass the Members Only
gate (MV-SET-014) because an app needs them before a user is even logged in —
and that exemption must NEVER accidentally widen to routes that should stay
gated, or narrow so the app can't render its own login screen on a fully
private community. `/serve` additionally re-checks live permission on every
single request, even for content that was public when its URL was signed.
Grounded against `includes/REST/Controller/AuthController.php`,
`ConfigController.php`, `InterestsController.php`, `AdminController.php`,
`ModerationController.php` (AI usage), `ActivityController.php` (feed),
`SignedUrlController.php` (`/serve`), and
`includes/REST/CommunityPrivacyGate.php`.

## Setup

- A fully private community (MV-SET-014 Members Only ON) for the exemption
  checks.
- A member's real credentials, a suspended member account, MV-SET-015
  toggleable.
- A private media item with a current, and an expired, signed URL.
- Mixed-privacy media/activity across authors with a follow graph, for the
  feed.

## Steps

### 1. MV-API-017 — Auth: nonce refresh, Application Password exchange
- **Action**: `POST /mvs/v1/auth/app-password` with correct credentials.
- **Assert**: an Application Password is issued (`AppCredentials::exchange()`).
  Throttling runs BEFORE the credential is read: two lockout buckets (`ip:` and
  `user:`) checked via `AppCredentials::is_locked_out()`, THEN a coarse
  `RateLimiter::check('auth_app_password', 20, 600)` — confirm by exhausting
  the IP bucket and observing `429 mvs_too_many_attempts` before any password
  validation occurs (i.e., a WRONG password count doesn't matter once
  locked out).
- **Action**: with MV-SET-015 off, repeat with correct credentials.
- **Assert**: refused (owner switch honored even for a valid credential).
- **Action**: as a suspended member (MV-ADM-015), repeat.
- **Assert**: refused — suspension gate applies even to issuing a FRESH
  credential.
- **Action**: compare error responses for wrong-username vs wrong-password
  vs a 2FA-protected account.
- **Assert**: failures are uniform — no response shape lets an attacker
  distinguish "no such user" from "wrong password"; a 2FA-protected account
  gets `409`, never a silent bypass.
- **Action**: `GET /mvs/v1/auth/nonce` logged out, then logged in.
- **Assert**: `401`/refused logged out (`is_user_logged_in()` gate); logged
  in, returns a fresh `wp_rest` nonce — this route is a browser-JS reliability
  detail only (auto-retry after `403 rest_cookie_invalid_nonce`), no
  end-user-visible behavior to assert beyond "doesn't break a session".

### 2. MV-API-018 — App: config and interests
- **Action**: on the fully private community, logged out, `GET
  /mvs/v1/app/config`.
- **Assert**: succeeds fully — NOT blocked by the Members Only gate. Confirm
  by checking `includes/REST/CommunityPrivacyGate.php`'s allowlist
  (`/mvs/v1/serve`, `/mvs/v1/app/config`) includes this route by exact path.
  The response's `features.messaging` reflects the REAL hard-off state
  (`Plugin::messaging_enabled()` AND `mvs_dm_access` not in
  `nobody|disabled|none`) — toggle MV-SET-020 off and confirm this flag
  flips to `false` in the same response (an app must not render a dead
  messaging tab).
- **Action**: logged out, `GET /mvs/v1/app/interests`; as a member, `POST
  /mvs/v1/me/interests` then `GET /mvs/v1/me/interests`.
- **Assert**: `/app/interests` is fully public; `/me/interests` read/write
  requires login. After setting interests, `GET /mvs/v1/users/suggested`
  (MV-API-014) reflects an interest-based boost in its ordering.

### 3. MV-API-019 — Admin: welcome dismiss
- **Action**: as a REGULAR member (not an admin), `POST
  /mvs/v1/admin/welcome/dismiss`.
- **Assert**: succeeds — `logged_in_permissions_check` is genuinely ANY
  authenticated user, per the code's own comment ("welcome dismiss is
  per-user state"), not scoped to admin-area users.
- **Action**: dismiss, then reload the relevant admin screen (or re-check the
  dismissal state) in a new session for the same user.
- **Assert**: stays dismissed — persisted, not session-only. Calling it
  twice is a harmless no-op.

### 4. MV-API-020 — AI usage
- **Action**: as an admin-capable caller (`manage_options` or
  `manage_mvs_settings`), `GET /mvs/v1/ai/usage`; as a plain moderator
  without those caps, repeat.
- **Assert**: admin-capable caller's numbers (calls, successes, failures,
  cost, budget remaining) match MV-ADM-017's AI Usage panel exactly — same
  underlying data. Moderator-only caller is refused, never shown site-wide
  spend data.
- **Note (catalog/manifest drift)**: this route is registered inside
  `ModerationController::register_routes()` (`settings_permissions_check`),
  not a dedicated AI or transactions controller — worth knowing when this
  route needs a code change, since it isn't where its catalog area name
  ("AI usage") would suggest.

### 5. MV-API-021 — Feed
- **Action**: logged out, `GET /mvs/v1/feed`.
- **Assert**: only public items appear (`ActivityController`,
  `permission_callback: '__return_true'`, privacy-filtered per item like
  MV-API-001).
- **Action**: as a member following some authors, `GET
  /mvs/v1/feed?scope=following`; separately `?scope=public` and
  `?scope=user`.
- **Assert**: `scope` is an explicit request parameter the caller must set —
  there is NO automatic blending/ranking of followed-first content into a
  default feed; within whichever scope, ordering is purely reverse-
  chronological. Confirm blocked-pair content is excluded from the feed for
  both parties (cross-check against `moderation-and-social.md`'s block
  rules — a feed is exactly the kind of surface a block gap slips through
  on).
- **Action**: check the feed for a DM/private upload from either party in a
  private conversation.
- **Assert**: never appears to anyone but the parties involved — these
  writes deliberately create no activity row at all.

### 6. MV-API-022 — Serve (signed media delivery)
- **Action**: fetch a private item via its valid, unexpired signed URL, with
  and without an `Accept: image/webp` / `image/avif` header.
- **Assert**: actual file bytes return; content negotiation serves WebP/AVIF
  when the `Accept` header supports it (MV-SET-009-adjacent).
- **Action**: fetch with an expired signature on the PRIVATE item; then on a
  PUBLIC item.
- **Assert**: private → refused. Public → served anyway (the documented
  `mvs_serve_expired_public_urls`-filterable cache-friendliness exception —
  confirm the filter, set to `false`, flips this to refused too).
- **Action**: fetch a signed URL for an item that has since been rejected by
  moderation, or whose author has since blocked the requester, using a
  signature that was VALID when first issued.
- **Assert**: refused — `/serve` re-checks `can_view()` LIVE on every
  request, even for a URL that was valid/public when signed (2.5.0 fix: no
  `'public'` short-circuit that could serve a since-moderated or
  since-blocked item straight from cache).
- **Action**: tamper one character of a valid signature.
- **Assert**: fails `hash_equals()` outright — no partial trust, no fallback
  path.

## Pass criteria

1. `/app/config` and `/auth/app-password` remain reachable on a fully private
   (Members Only) community; both are Rule-2-allowlisted by exact path in
   `CommunityPrivacyGate`.
2. `/auth/app-password` throttles by IP AND username bucket before reading
   any credential, and never distinguishes failure reasons in its response.
3. `/app/config`'s `features` map reflects the REAL current state of each
   toggle (especially `messaging`), never a stale/hardcoded value.
4. `/serve` re-checks live permission on every request; only the
   public-expired-URL case is exempt, and that exemption is itself
   filterable off.
5. `/feed` never blends scopes, never surfaces blocked-pair or DM content to
   the wrong audience.
6. `/admin/welcome/dismiss` works for any logged-in user and persists past
   the session.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `/app/config` starts requiring login on a private community | its path dropped from `CommunityPrivacyGate`'s allowlist | `includes/REST/CommunityPrivacyGate.php` |
| `/app/config`'s `messaging` flag stays `true` after MV-SET-020 is turned off | flag computed once/cached instead of read live | `includes/REST/Controller/ConfigController.php::get_config` |
| `/serve` serves a since-blocked/since-rejected item on an old valid signature | the `'public'` short-circuit reintroduced, skipping the live `can_view()` re-check | `includes/REST/Controller/SignedUrlController.php`, `includes/Services/SignedUrlService.php` |
| `auth/app-password` lockout bypassed by rotating IPs | username bucket removed, only IP bucket checked | `includes/Auth/AppCredentials.php` |
| Feed blends `following` into the default/public scope | `scope` param defaulted/merged instead of read as an explicit request value | `includes/REST/Controller/ActivityController.php` |

---
journey: profile-view-and-follow
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-PRF-001, MV-PRF-002, MV-PRF-003, MV-PRF-004, MV-PRF-005, profile-view, follow-toggle, followers-list]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Member A and Member B, Member B has at least one public upload"
estimated_runtime_minutes: 6
---

# A member's profile renders correctly per viewer, and follow/unfollow work with live counts

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`, has a public upload).

## Steps

### 1. View a profile — content and privacy-filtering (MV-PRF-001)
- **Action**: `playwright_navigate $SITE_URL/media/@<memberB>/` logged out.
- **Expect**: header (avatar, display name, follower/following counts) plus a grid of Member B's PUBLIC media only.
- **Action**: log in as Member A and revisit.
- **Expect**: same header; grid may now also include `members`-privacy items.
- **Action**: log in as Member B (the owner) and visit their own profile.
- **Expect**: grid includes their own private/friends/group items too.
- **Action**: visit `/media/@nonexistent-user/`.
- **Expect**: the branded 404 for "profile" (not a generic WordPress 404).
- **Action**: visit `/media/@<memberB>/page/2/` if a 2nd page exists.
- **Expect**: paginates correctly with HTTP 200, not a soft-404.

### 2. Action row hides for the owner and for a logged-out visitor (MV-PRF-002)
- **Action**: as Member B, view your own profile — check for Follow/Message/Report/Block buttons.
- **Expect**: zero action buttons.
- **Action**: log out, view the same profile.
- **Expect**: zero action buttons.
- **Action**: as Member A, view Member B's profile.
- **Expect**: a Follow button appears (plus Message/overflow-menu items if messaging/reports are enabled).

### 3. Follow a member (MV-PRF-003)
- **Action**: as Member A, note Member B's current follower count; click Follow.
- **Expect**: `POST /mvs/v1/users/{memberB_id}/follow` returns 200/201; button flips to "Following"; the follower/following counts update from THIS SAME response — no separate re-fetch. Member B receives a `new_follower` notification ("... started following you").
- **Action**: as Member A, follow someone who has blocked Member A (or whom Member A has blocked).
- **Expect**: refused (`RestGuards::deny_if_blocked()`).

### 4. Unfollow a member (MV-PRF-004)
- **Action**: as Member A (already following Member B), click "Following" to unfollow.
- **Expect**: `DELETE /mvs/v1/users/{memberB_id}/follow` returns 200/204; button reverts to "Follow"; counts update; Member B gets NO notification for the unfollow.
- **Action**: as a blocked-either-way pair who had followed before the block, attempt to unfollow.
- **Expect**: succeeds — unfollow is a safety-valve retraction that a block never prevents.

### 5. Followers / following lists (MV-PRF-005)
- **Action**: open the followers modal from Member B's profile (public); paginate if there are enough entries.
- **Expect**: a paginated list of member cards, `X-WP-Total` backing the pagination.
- **Action**: open it for a member with zero followers.
- **Expect**: "no followers yet" empty state, not a blank modal.
- **Action**: as Member A, visit `/me/following` and `/me/followers`.
- **Expect**: your own lists render.

## Pass criteria

ALL of the following hold:
1. Profile renders the correct privacy-filtered grid per viewer type (visitor/other member/owner); unknown username 404s with the branded profile 404; page 2 works.
2. Action row is completely absent for the owner and for a logged-out visitor; present for another logged-in member.
3. Follow updates the button, both counts (from the same response), and fires exactly one `new_follower` notification; a blocked relationship refuses a new follow.
4. Unfollow reverts the button, updates counts, fires no notification, and is never blocked by a block relationship.
5. Followers/following lists paginate via `X-WP-Total` and show an empty state at zero.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Owner sees Follow/Message on their own profile | owner-exclusion check missing | `templates/partials/profile-actions.php` |
| Counts require a page reload to update after Follow | response not read for updated counts client-side | `src/blocks/*/view.js` (profile/follow action) |
| Unfollow blocked by a block relationship | `deny_if_blocked()` applied to the DELETE route too broadly | `includes/REST/Controller/FollowController.php` |
| Follow succeeds against a blocked pair | `deny_if_blocked()` not wired to the follow permission callback | `includes/REST/Controller/FollowController.php` |

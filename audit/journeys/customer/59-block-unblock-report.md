---
journey: block-unblock-report
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-PRF-006, MV-PRF-007, MV-PRF-008, MV-PRF-009, block-member, unblock-member, blocked-list, report-member-pro-gated]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Member A and Member B, Member B has public content"
estimated_runtime_minutes: 6
---

# Blocking a member is one-directional, unblocking restores access, and the blocked list is a shared component

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`, has public media).

## Steps

### 1. Block a member (MV-PRF-006)
- **Action**: as Member A, open the "More actions" overflow menu on Member B's profile; click Block.
- **Expect**: `POST /mvs/v1/users/{memberB_id}/block` returns 200/201; menu item label flips to "Unblock" AND its `aria-label` flips from "Block this member" to "Unblock this member" (verify in the DOM, not just the visible label). Confirm in the browser whether a confirm dialog appears — per the catalog, Block itself should NOT use the shared confirm (only Report and destructive deletes do); report if it does.
- **Action**: attempt Block 11 times within 60 seconds (against 11 different target accounts, or repeatedly toggling).
- **Expect**: rate-limited at 10/60s — the 11th call in the window returns 429.
- **Action**: as Member A (the blocker), view Member B's public media, profile grid, and thumbnails.
- **Expect**: Member A retains FULL access — blocking is one-directional and never restricts the blocker.
- **Action**: as Member B (the blocked), try to view Member A's public media (single page, REST, profile grid, lightbox, thumbnails, download), follow Member A, comment/react/favourite/share on Member A's media, and message Member A.
- **Expect**: every one of these is denied.
- **Action**: as Member B, un-react to something reacted to before the block, decline a pending conversation from Member A, unfollow Member A, or delete their own account.
- **Expect**: all of these explicit exceptions continue to work — a block never traps the blocked party out of a retraction.

### 2. Unblock a member (MV-PRF-007)
- **Action**: as Member A, click "Unblock" in the same overflow menu.
- **Expect**: `DELETE /mvs/v1/users/{memberB_id}/block` returns 200/204; label reverts to "Block". Reciprocal access is restored — Member B can again view/interact with Member A's public content (subject to any independent privacy settings that were never about the block).
- **Action**: search wp-admin for any surface that lists, audits or lets an admin undo a block on a member's behalf.
- **Expect**: NONE exists — confirm this documented gap; a site owner cannot help a member who blocked someone by mistake except by asking them to unblock themselves.

### 3. Blocked list (MV-PRF-008)
- **Action**: as Member A (having blocked Member B), open the blocked-members list from the Edit Profile screen / dashboard's Edit Profile section.
- **Expect**: `GET /mvs/v1/me/blocked` returns Member B; the list shows an Unblock action per row. Confirm the SAME component renders whether reached from the standalone Edit Profile page or the dashboard's Edit Profile panel (one shared list, not two divergent ones).
- **Action**: as a member who has blocked no one, open the same list.
- **Expect**: an empty state, not a blank panel.

### 4. Report a member is Pro-gated in Free (MV-PRF-009)
- **Action**: as Member A, open the overflow menu on Member B's profile on a Free-only install (no Pro, no override filter); look for a "Report" item.
- **Expect (verify and report the actual behavior)**: `ReportService::reports_enabled()` gates whether the menu item is even offered — confirm whether it is hidden entirely, or shown and then 403s on click. If shown, the 403 MUST surface as a visible error toast, never a silent no-op.
- **Action**: on the same install, report a MEDIA ITEM (not a member) via MV-MED-013's flow.
- **Expect**: succeeds normally — reporting a media item is fully available in Free; only reporting a PERSON is Pro-gated. Do not conflate the two when triaging a bounce.

## Pass criteria

ALL of the following hold:
1. Block succeeds, rate-limits at 10/60s, flips both the label and `aria-label`; is strictly one-directional (blocker unaffected, blocked party loses every listed interaction); the named safety-valve retractions keep working through a block.
2. Unblock restores reciprocal access and reverts the label; no wp-admin surface exists to see/undo a block (confirmed absence).
3. The blocked list is one shared component reachable from both surfaces, with a proper empty state.
4. Reporting a MEMBER is Pro-gated (hidden or 403, never a silent failure); reporting a MEDIA ITEM works fully in Free regardless.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Blocker also loses access to the blocked member's content | block check applied bidirectionally instead of one-directionally | `includes/REST/RestGuards.php::deny_if_blocked()` |
| Un-react/decline/unfollow/account-delete blocked by a block relationship | safety-valve exception list incomplete in the gate | `includes/REST/RestGuards.php` |
| Block bypasses its rate limit | `RateLimiter::check('block_user', 10, 60)` removed | `includes/REST/Controller/ReportController.php::block_user()` |
| `aria-label` does not flip with the visible label | template only updates text, not the attribute | `templates/partials/profile-actions.php` |
| Report-a-member silently no-ops on Free (no toast) | client swallows the 403 instead of surfacing it | wherever the overflow menu's Report action posts |

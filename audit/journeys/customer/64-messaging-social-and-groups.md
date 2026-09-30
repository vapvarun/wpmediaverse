---
journey: messaging-social-and-groups
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-MSG-007, MV-MSG-008, MV-MSG-010, MV-MSG-011, message-reactions, unsend-vs-delete, message-requests, group-conversations]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Members A, B, C, D available for group testing"
  - "mvs_dm_access set to a level that produces a Request (e.g. followers) for the requests step"
estimated_runtime_minutes: 8
---

# Message reactions, unsend vs. delete, accepting/declining requests, and group conversations

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`), plus Members C and D for the group step.

## Steps

### 1. React to a message (MV-MSG-007)
- **Action**: as Member A, react to a message in a shared conversation with an emoji.
- **Expect**: `POST /messages/{id}/reactions` succeeds; the reaction is visible to ALL participants live, no page/panel reload.
- **Action**: remove the reaction.
- **Expect**: `DELETE /messages/{id}/reactions` succeeds.
- **Action**: in a conversation where the two participants have since blocked each other but a reaction predates the block, remove that reaction.
- **Expect**: still succeeds — reaction removal is a safety-valve action that always works even in a blocked-pair scenario.

### 2. Unsend vs. delete (MV-MSG-008)
- **Action**: as Member A, send a message, then use "Delete" on it (as Member A's own message).
- **Expect**: `DELETE /messages/{id}` removes it from Member A's OWN view only; as Member B, the message is STILL visible.
- **Action**: send another message, then use "Unsend".
- **Expect**: `DELETE /messages/{id}/unsend` removes it for EVERYONE; as Member B, the message is gone too.
- **Action**: compare the two controls in the UI.
- **Expect**: visibly distinct labels/icons — a tester must be able to tell which one they're about to trigger before clicking.
- **Action**: click "Unsend".
- **Expect (verify, report the actual behavior)**: confirm whether it is gated by the shared confirm dialog given its everyone-facing effect; the catalog flags this as unverified — report which way it actually ships.

### 3. Message requests: accept / decline (MV-MSG-010)
- **Action**: with `mvs_dm_access` set so a non-follower's message becomes a Request (per customer/63 step 2), have Member B (non-follower) message Member A; open Member A's Requests tab (`GET /me/conversations?tab=requests`).
- **Expect**: the pending request appears there, not in the normal inbox.
- **Action**: Accept it.
- **Expect**: `POST /conversations/{id}/accept` moves it into the normal inbox; Member A can now reply normally.
- **Action**: have Member C message Member A the same way (a second Request); Decline it.
- **Expect**: `POST /conversations/{id}/decline` removes/archives it with NO reply option offered.
- **Action**: have Member D send a Request, then block Member A before A accepts it; as Member A, try to Accept.
- **Expect**: explicitly denied — accepting a request from someone who has since blocked you is refused.
- **Action**: repeat the block-before-response scenario but click Decline instead.
- **Expect**: Decline still succeeds — declining is a safety-valve retraction that always works.

### 4. Group conversations (MV-MSG-011)
- **Action**: as Member A, start a new group ("Start new group"), name it "Journey Group", add Members C and D.
- **Expect**: group conversation created with a roster and title.
- **Action**: send a message in the group.
- **Expect**: behaves like a 1:1 conversation but visible to all members with the right role.
- **Action**: rename the group ("Journey Group Renamed").
- **Expect**: title updates for all participants.
- **Action**: remove Member D from the group (as a participant with the right role).
- **Expect**: Member D loses access; remaining participants see the roster update.
- **Action**: as Member C, leave the group.
- **Expect**: a confirm dialog gates "Leave group" — distinct from a 1:1 conversation, since 1:1 has no "leave" concept at all; verify a 1:1 conversation genuinely offers no such control.

## Pass criteria

ALL of the following hold:
1. Message reactions are live for all participants with no reload; reaction removal always succeeds, even across a since-formed block.
2. Delete removes a message from only the deleter's view; Unsend removes it for everyone; the two controls are visually distinct.
3. A Request-tier message lands in the Requests tab, not the inbox; Accept moves it to the inbox and enables replies; Decline removes it with no reply option; accepting a since-blocking sender is denied while Decline still succeeds.
4. A group conversation supports naming, adding/removing members with roster updates, and a confirmed Leave action that has no 1:1 equivalent.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Delete removes a message for both participants | `delete_message()` scoped to all rows instead of the caller's own visibility flag | `includes/Messaging/MessagingController.php::delete_message()` |
| Accepting a request from a since-blocked sender succeeds | block check missing from the accept endpoint | `includes/Messaging/MessagingController.php` (accept handler) |
| Declining is blocked by a block relationship | decline incorrectly gated as a write instead of a safety-valve retraction | `includes/Messaging/MessagingController.php` (decline handler) |
| Leaving a group has no confirm dialog | shared confirm not wired to the leave-group action | messaging JS (group actions) |
| A removed group member still receives/sends messages | participant removal not enforced server-side on subsequent sends | `includes/Messaging/MessagingService.php` |

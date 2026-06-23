---
journey: messaging-send-reliability
plugin: wpmediaverse
priority: critical
roles: [member]
covers: [messaging-send-reliability, dm-failed-retry, dm-empty-states]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=<login>)"
  - "Two members exist (A and B) who can DM each other (followers/mutual per mvs_dm_access)"
estimated_runtime_minutes: 6
---

# Direct messages send reliably and never look falsely delivered (regression sentinel)

**Why this journey exists**: A cluster of 2026-06 fixes hardened the DM optimistic-send
path and its empty/loading states:
- A failed send used to render identical to a delivered message (the temp message set
  underscore-prefixed `_failed`/`_sending` flags that Interactivity does not track inside
  `data-wp-each`, and `chat-message.php` had no binding). Now `enrichMessage` derives
  camelCase `isSending`/`isFailed`/`hideCheck`, the bubble shows a "Sending…" state then a
  "Not sent — Retry" control on failure, and `actions.retrySend` re-sends.
- A brand-new conversation rendered a blank message panel (no empty state), and the
  conversation list showed "No conversations yet" while it was still loading. Now a
  `showThreadEmpty` / `showListEmpty` / `loadingConversations` state covers each.

## Setup

- Member A: autologin via `?autologin=A`.
- Open the chat panel (or `$SITE_URL/messages/`).

## Steps

### 1. Conversation list shows a loading state, not a false "empty"
- **Action**: open the chat panel with the network throttled (or immediately on first load).
- **Expect**: while `state.loadingConversations` is true, `.mvs-chat-list__loading` ("Loading conversations…") is visible and `.mvs-chat-list__empty` is hidden. After load, exactly one of: the list, or `.mvs-chat-list__empty` (only if zero conversations).

### 2. A brand-new conversation shows an empty-thread state, not a blank panel
- **Action**: start a new conversation with Member B (no messages yet).
- **Expect**: `.mvs-chat-messages__empty` ("No messages yet / Say hello…") is visible; the panel is not blank.

### 3. A successful send clears the empty state and shows the delivered check
- **Action**: type "hello" and send.
- **Expect**: the message appears; briefly `.mvs-chat-msg__sending`; then the `__check` (✓✓) shows and `.mvs-chat-messages__empty` is gone. DB: a row exists in `wp_mvs_messages` for this conversation.

### 4. A FAILED send shows an error + retry, NOT a delivered message
- **Action**: force the next POST to fail (offline, or block `POST /wp-json/mvs/v1/conversations/{id}/messages`), then send "this should fail".
- **Expect**: the bubble shows `.mvs-chat-msg__retry` ("Not sent — Retry") and NO `__check` (✓✓). The composer is not silently successful. A toast surfaced the error.

### 5. Retry re-sends successfully
- **Action**: restore the network, click the "Not sent — Retry" control on the failed bubble.
- **Expect**: the bubble transitions sending → delivered (✓✓), no duplicate row in `wp_mvs_messages`.

## Pass criteria

ALL hold:
1. Loading state shows while the list loads; "No conversations yet" only appears after loading with zero conversations.
2. An empty thread shows the "No messages yet" state, never a blank panel.
3. A successful send shows the ✓✓ check; a failed send shows "Not sent — Retry" and NO check.
4. Retry re-sends and the message is delivered exactly once.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Failed send shows ✓✓ / looks delivered | isFailed/hideCheck not derived, or chat-message.php binding missing | `assets/js/messaging.js` (enrichMessage), `templates/partials/chat-message.php` (meta block) |
| New conversation panel is blank | showThreadEmpty missing / not bound | `assets/js/messaging.js` (getter), `templates/partials/chat-conversation.php` |
| List reads "No conversations yet" mid-load | loadingConversations not bound / showListEmpty missing | `templates/partials/chat-list.php`, `assets/js/messaging.js` |
| Retry duplicates the message | retrySend re-posts without replacing the temp | `assets/js/messaging.js` (retrySend) |

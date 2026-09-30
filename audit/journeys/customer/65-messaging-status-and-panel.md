---
journey: messaging-status-and-panel
plugin: wpmediaverse
priority: high
roles: [subscriber, anonymous]
covers: [MV-MSG-009, MV-MSG-012, MV-MSG-013, MV-MSG-014, MV-MSG-015, MV-MSG-016, read-unread-badge, typing-indicator, conversation-search, chat-panel-visibility, online-status, server-side-timestamps]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Two members with a shared conversation containing several messages, one with a distinctive phrase"
  - "Ability to change one client device's clock/timezone for the timestamp check"
estimated_runtime_minutes: 8
---

# Read/unread badges, typing, in-conversation search, chat-panel visibility scoping, online status, and server-authoritative timestamps

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`), an existing conversation with an unread message from B waiting for A, and several messages including one with the phrase "journey-search-term".

## Steps

### 1. Mark conversation read / unread badge (MV-MSG-009)
- **Action**: as Member A, note the unread badge count; open the conversation with B's unread message.
- **Expect**: `POST /conversations/{id}/read` fires; the badge decrements immediately (from `GET /me/messages/unread-count`) with no full chat-panel reopen or page refresh needed.

### 2. Typing indicator (MV-MSG-012)
- **Action**: with both Member A and B viewing the same open conversation in separate sessions, start typing as Member A (do not send).
- **Expect**: Member B sees a "typing…" indicator while A is actively composing (`POST /conversations/{id}/typing`); it clears shortly after A stops typing.
- **Action**: as Member A, start typing, then close the tab/navigate away without sending.
- **Expect**: the indicator on B's side clears via its own TTL/timeout rather than persisting forever.

### 3. Search within a conversation (MV-MSG-013)
- **Action**: in the open conversation, search "journey-search-term".
- **Expect**: `GET /conversations/{id}/messages/search?q=journey-search-term` returns the matching message(s) (up to 50/page), and they surface in the UI.
- **Action**: search a phrase that matches nothing.
- **Expect**: a clear "no messages found" state, not an empty blank pane.

### 4. Chat panel visibility scoping (MV-MSG-014)
- **Action**: `wp option update mvs_chat_panel_visibility everywhere`; visit a totally unrelated page (e.g. the site's blog front page).
- **Expect**: the floating chat icon appears there too.
- **Action**: `wp option update mvs_chat_panel_visibility mvs_pages`; recheck a MediaVerse page (Explore/Dashboard) AND the unrelated blog page.
- **Expect**: icon present on the MediaVerse page, absent on the unrelated page.
- **Action**: `wp option update mvs_chat_panel_visibility disabled`; reload any page; `curl -s $SITE_URL/... | grep -c 'mvs-chat-panel'`.
- **Expect**: ZERO occurrences of `.mvs-chat-panel` markup in the raw page source anywhere — not merely hidden via CSS.
- **Action**: with the same "disabled" setting, visit `/messages/` directly.
- **Expect**: the dedicated messages page still works — this setting only controls the floating slide-out icon, never the dedicated page. Restore `mvs_chat_panel_visibility` to its original value.

### 5. Online status visibility (MV-MSG-015)
- **Action**: `wp option update mvs_show_online_status followers`; ensure Member B follows Member A but Member C does not; have Member A go online (open a page that pings presence).
- **Expect**: as Member C (non-follower), NO online indicator shows for Member A; as Member B (follower), it DOES show while Member A is genuinely online.
- **Action**: as Member A, set the per-profile "Show your online status" to No; recheck as Member B (a follower).
- **Expect**: Member A's own override wins — no indicator shows for anyone, even a follower, once the member has opted out personally.

### 6. DM timestamps are server-side (MV-MSG-016)
- **Action**: set the sending device's clock to a wildly different time/timezone (e.g. +12 hours or a past date); send a message.
- **Expect**: the displayed timestamp reflects the SERVER's clock at write time (converted to the viewer's site timezone for display), NOT the skewed device clock — the message must not appear sent "in the future" or "in the past" relative to the server's own time.
- **Action**: inspect the `POST /conversations/{id}/messages` request schema/payload.
- **Expect**: no client-settable timestamp field exists at all — confirm the REST route's accepted params.

## Pass criteria

ALL of the following hold:
1. Opening a conversation marks it read and decrements the badge without a reload.
2. Typing shows live to the other participant and clears both on stop-typing and via a TTL if the tab closes mid-type.
3. In-conversation search returns matches (capped at 50/page) and shows a clear no-results state.
4. Chat-panel visibility scopes the floating icon exactly per the 4 modes; "disabled" emits zero `.mvs-chat-panel` markup in the raw source; `/messages/` always works regardless of the setting.
5. Online status respects the site-wide scope (Everyone/Followers/Nobody) AND the member's own override, which always wins when set to "No".
6. Message timestamps are immune to a skewed client clock — server-authoritative, no client-settable timestamp field exists.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Badge requires a page reload to update | client not re-fetching/reading the unread-count response after marking read | messaging JS (badge state) |
| Typing indicator never clears after a closed tab | no TTL/timeout on the typing state | `includes/Messaging/MessagingService.php` (typing handler) |
| "Disabled" chat visibility still emits `.mvs-chat-panel` in source, just hidden via CSS | template still renders the panel markup unconditionally | `templates/partials/shared-ui-frame.php` (chat panel include) |
| A non-follower sees an online indicator with "Followers only" set | scope check not applied to the presence render | wherever online-status badges render, messaging JS |
| Member's own "No" override ignored for a follower viewer | per-member override not read ahead of the site-wide scope | online-status resolution logic |
| A skewed client clock changes the displayed send order/time | a client-submitted timestamp field accepted by the send-message route | `includes/Messaging/MessagingController.php` (send_message schema) |

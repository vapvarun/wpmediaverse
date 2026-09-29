---
journey: messaging-lifecycle
plugin: wpmediaverse
priority: critical
roles: [subscriber, administrator]
covers: [MV-MSG-001, MV-MSG-002, MV-MSG-003, MV-MSG-004, MV-MSG-005, MV-MSG-006, messaging-master-switch, dm-access-levels, conversation-start, attachment-send, media-share-into-dm]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Two member accounts (A and B), no follow relationship between them initially"
  - "An image under 10MB and a PDF, for the attachment step"
estimated_runtime_minutes: 9
---

# Messaging can be switched off cleanly, DM access rules gate who can send, and a conversation can carry text, attachments, and shared media

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`).
- `mvs_messaging_enabled` ON, `mvs_dm_access` at default `everyone` to start.

## Steps

### 1. Messaging master switch, off is total (MV-MSG-001)
- **Action**: `wp option update mvs_messaging_enabled 0`; as Member A, look for the chat panel icon, the "Message" button on Member B's profile, and visit `/messages/` directly.
- **Expect**: NO chat panel icon anywhere, NO "Message" button on any profile, `/messages/` shows nothing to render — a clean, total absence, never a broken button that 404s or an icon opening an empty panel.
- **Action**: `curl -i $SITE_URL/wp-json/mvs/v1/conversations` (as Member A).
- **Expect**: the messaging REST routes do not respond normally (refused/unavailable).
- **Action**: `wp option update mvs_messaging_enabled 1`; reopen an OLD conversation created before the switch was toggled off (if one exists from a prior run, or create one now and toggle off/on around it).
- **Expect**: prior conversations come back exactly as they were — no data loss.
- **Action**: with the switch off, request a GDPR export/erase for messaging data (see privacy/gdpr-export-erase.md).
- **Expect**: still registered and functional even while the switch is off.

### 2. DM access levels (MV-MSG-002)
- **Action**: `wp option update mvs_dm_access everyone`; as Member A (no follow relationship with B), message Member B.
- **Expect**: sends directly into B's inbox.
- **Action**: `wp option update mvs_dm_access followers`; as Member A (still not following B), message B again.
- **Expect**: lands as a pending REQUEST in B's Requests tab, NOT a hard failure — the sender gets feedback that it was sent as a request, not silently swallowed.
- **Action**: `wp option update mvs_dm_access mutual`; as Member A (no mutual follow), message B.
- **Expect**: refused with "This member only accepts messages from people they follow back."
- **Action**: `wp option update mvs_dm_access nobody`; as Member A, message B.
- **Expect**: refused with "This member isn't accepting messages right now." Existing conversations remain readable — only new sends are blocked.
- **Action**: set B's own Edit Profile "Who can message you" to something MORE restrictive than the site ceiling (e.g. site = everyone, B's own = nobody).
- **Expect**: B's own preference wins (tighter). Attempt the reverse (B sets looser than the site ceiling).
- **Expect**: the site ceiling still applies — a recipient's preference can only tighten, never loosen, the site-wide rule.
- **Action**: `wp option update mvs_dm_min_age 30`; as a freshly-created Member A' account (< 30 days old), message anyone.
- **Expect**: refused with "Your account is too new to message this member yet." regardless of the access-level setting. Restore all options afterward.

### 3. Start a new conversation (MV-MSG-003)
- **Action**: as Member A, click "Message" on Member B's profile (eligible per the access level); type a first message; send.
- **Expect**: `POST /mvs/v1/conversations` creates (or reuses an existing) conversation; the message appears in it.
- **Action**: attempt to message yourself.
- **Expect**: refused, "You can't send a message to yourself."
- **Action**: attempt to send an empty message, then a message far beyond the length limit.
- **Expect**: both refused client-side where possible ("Your message is empty." / "That message is too long to send.") before hitting the network.
- **Action**: as Member B, block Member A, then have Member A try to message B again.
- **Expect**: refused, "You can no longer message this member."

### 4. Send a text message + rate limit (MV-MSG-004)
- **Action**: in an existing conversation, type and send a message.
- **Expect**: appears in the thread instantly for the sender; composer clears on success.
- **Action**: send several messages rapidly to exceed the rate limit.
- **Expect**: refused beyond the limit: "You're sending messages too quickly. Please wait a moment and try again." The composer keeps the last typed text on a failed send rather than losing it, and shows the SPECIFIC denial reason (rate-limited here), not a generic error.
- **Action**: get removed from a conversation (e.g. a group, see customer/64), then try posting to it.
- **Expect**: refused, "You can no longer post to this conversation."

### 5. Send an attachment (MV-MSG-005)
- **Action**: click the attachment icon; pick the valid image under 10MB.
- **Expect**: upload progress shown while uploading BEFORE the message actually sends; on success the attachment sends as a message.
- **Action**: try a file over 10MB.
- **Expect**: refused, naming the size reason.
- **Action**: try the PDF.
- **Expect**: refused — PDFs are excluded from DM attachments (verified by real file content via `finfo_file()`, not by extension, so a renamed PDF is still caught).

### 6. Share an existing media item into a conversation (MV-MSG-006)
- **Action**: from a media item's Share options, choose to send it via DM to Member B; pick or create the target conversation.
- **Expect**: `POST /conversations/{id}/messages` with `media_id` creates a message referencing the EXISTING item (no duplicate upload); Member B sees an inline preview card (`chat-media-card.php`) respecting THEIR OWN view permission on it.
- **Action**: after sharing, change the shared item's privacy so Member B can no longer view it; reopen the conversation as Member B.
- **Expect**: the card degrades gracefully (e.g. a "no longer available" placeholder), never breaking the surrounding chat render.

## Pass criteria

ALL of the following hold:
1. Messaging off is a clean, total absence everywhere; conversations survive a toggle off/on with no data loss; GDPR export/erase keeps working regardless.
2. Each `mvs_dm_access` level behaves exactly as specified, including the Requests-not-refusal distinction for Followers-only; a recipient's own preference can only tighten, never loosen, the site ceiling; `mvs_dm_min_age` blocks too-new accounts regardless of access level.
3. Starting a conversation refuses self-messaging, empty/over-length messages, and a blocked recipient, each with a specific message.
4. Text sends land instantly for the sender and rate-limit with a specific message that preserves the composer's draft; removal from a conversation blocks further posting.
5. Attachments respect the 10MB cap and the PDF exclusion (content-sniffed, not extension-based), with visible upload progress.
6. Sharing existing media into a chat creates a reference, not a duplicate; the recipient's own view permission governs the card, and it degrades gracefully if access is later revoked.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A "Message" button still appears with messaging disabled | button render not checking `mvs_messaging_enabled` | wherever profile actions render, `templates/partials/profile-actions.php` |
| Followers-only sends hard-fail instead of becoming a Request | request-tier branch missing from the send-message permission logic | `includes/Messaging/MessagingService.php` |
| Recipient's own preference can loosen the site ceiling | tighten-only comparison inverted or missing | `includes/Messaging/MessagingService.php` |
| Rate-limited send loses the composer's typed text | composer clears optimistically before the response is known | messaging JS composer send handler |
| A renamed PDF (`.jpg` extension) is accepted as a DM attachment | attachment type check using extension instead of `finfo_file()` | `includes/Messaging/MessagingController.php` (attachment upload) |
| Shared media card breaks the whole thread when access is revoked | no graceful-degrade branch in `chat-media-card.php` | `templates/partials/chat-media-card.php` |

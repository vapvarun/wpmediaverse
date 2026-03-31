# WPMediaVerse Direct Messaging

**Your members can talk to each other inside your platform. No Slack link in the bio. No "DM me on Instagram." Just a conversation that stays on your site.**

---

## Why On-Site Messaging Matters

Every time a community interaction leaves your platform — to continue on a third-party app, in an email thread, or on social media — you lose visibility into that relationship and the engagement it generates.

More practically: members who have ongoing conversations with each other stay. They feel connected to specific people in the community, not just to the site's content. Retention research across social platforms consistently shows that the number of active friend connections is the strongest predictor of whether a user churns.

WPMediaVerse includes a complete direct messaging system built into the media platform. No plugin subscription to a third-party chat service. No iframe. No external data storage.

---

## What the Messaging System Includes

### Text Messages (Free)

Members send and receive text messages in a standard threaded conversation interface. Message threads are accessible from member profiles (a DM button) and from a dedicated messages page in the dashboard.

The conversation list shows all active threads ordered by most recent activity. Each thread shows the last message preview and a timestamp.

---

### Media Sharing (Free)

Members can attach photos or videos from their WPMediaVerse library to any message. The recipient sees a thumbnail in the thread and can click to view the full item in the lightbox.

This is meaningfully different from sharing a link. A shared photo opens inside the same platform experience — with reactions, the full-resolution image, and navigation through the sender's library if they choose to share an album.

---

### Voice Messages (Pro)

Members record voice messages directly in the message composer. The recording interface is in-browser — no app download, no external service. Recordings are stored in WPMediaVerse's media tables alongside regular uploads.

Recipients see a voice message card in the thread with a play button, waveform, and duration. They can play it inline without leaving the conversation.

**When voice messages are used more than text:** Voice is faster for anything longer than two or three sentences. It conveys tone that text loses. In creative communities, audio feedback on a photo — "I love what you did with the light in the top right, but the foreground feels heavy" — is more useful than typing that same sentence.

---

### Read Receipts (Pro)

Sent messages show delivery and read status. A message shows as delivered when it reaches the recipient's thread. It shows as read when the recipient opens the thread.

The sender sees this as a subtle status indicator next to their message — not a prominent notification, just context.

**Why this matters:** Read receipts change messaging from asynchronous email to something closer to a real conversation. Knowing your message was seen — even if no reply has come — removes the uncertainty that causes people to send follow-up messages or assume the message was lost.

---

### Typing Indicators (Pro)

When a member is typing a reply, the other participant sees a typing indicator in the conversation — three animated dots, the standard pattern.

**Why this matters:** Typing indicators signal that a response is coming, which keeps both participants present in the conversation. Without them, a 30-second pause while someone composes a reply looks identical to being ignored.

---

## Privacy and Blocking

Members can choose who can message them:

- **Everyone** — Any site member can initiate a conversation
- **People I follow** — Only members the recipient follows can send first messages
- **Nobody** — Messaging is disabled entirely for that user

Blocking a member removes their messages from the inbox and prevents future messages from them. Block management is available in account settings.

---

## Admin Visibility

Admins can see message counts and thread activity in aggregate in the admin dashboard. Individual message content is not visible to admins by default — this is intentional. Surfacing private message content to admins without cause creates a trust problem with members.

If your platform requires moderation of private messages (for minor safety, for example), a message reporting tool lets members flag a message for admin review. Flagged messages enter a moderation queue where admins can review only the flagged content.

---

## How It Is Stored

Message data is stored in WPMediaVerse's custom tables, not in WordPress options or `wp_posts`. This means:

- Message history survives plugin updates cleanly
- The data is included in GDPR export requests automatically
- A GDPR erasure request removes all message data for that user
- Deactivating the plugin does not delete message history; the tables persist until explicitly removed

---

## Who This Feature Is For

**Photography and creative communities** where members give each other feedback, discuss collaboration, and share work in progress. Voice messages are especially valuable here.

**BuddyPress communities** where the activity feed drives public interaction but members also want private communication channels. WPMediaVerse messaging works alongside (not instead of) BuddyBoss's or BuddyPress's own messaging if you have that active.

**Membership sites** where mentors and students, clients and creators, or experts and learners need a private channel for communication.

**Marketplace-style communities** where buyers and sellers (or commissioners and artists) need to negotiate privately.

---

## Messaging Is Included in the Free Version (Core Features)

Text messaging and media sharing are included in the free version of WPMediaVerse. Voice messages, read receipts, and typing indicators require Pro.

| Feature | Free | Pro |
|---------|------|-----|
| Text messaging | Yes | Yes |
| Media sharing in messages | Yes | Yes |
| Message thread list | Yes | Yes |
| Member blocking | Yes | Yes |
| Message privacy settings | Yes | Yes |
| Voice messages | No | Yes |
| Read receipts | No | Yes |
| Typing indicators | No | Yes |

---

## Get the Full Messaging Experience

The core messaging system is free. Pro messaging — voice messages, read receipts, and typing indicators — is included in every WPMediaVerse Pro license.

**[Get WPMediaVerse Pro]**   **[Download Free]**

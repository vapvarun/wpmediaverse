---
journey: notifications
plugin: wpmediaverse
priority: high
roles: [subscriber]
covers: [MV-NTF-001, MV-NTF-002, MV-NTF-003, MV-NTF-004, MV-NTF-005, notification-bell, mark-read, notification-types, email-preferences, mentions]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "Two member accounts (A and B); no BuddyPress active for the bell-location step"
estimated_runtime_minutes: 7
---

# The notification bell, mark-read, all 7 notification types, email preferences, and @mentions

## Setup

- Member A (`?autologin=<memberA>`), Member B (`?autologin=<memberB>`), Member B owns a public media item.

## Steps

### 1. Notification bell location and badge (MV-NTF-001)
- **Action**: on a standalone (non-BuddyPress) Free install, have Member B follow Member A; open Member A's My Media dashboard.
- **Expect**: `.mvs-notification-bell` in the rail head shows an unread badge count (cached 300s); clicking it opens a dropdown listing the notification, unread visually distinguished via `.mvs-notification-unread`.
- **Action**: check Explore and a single-media page for any equivalent bell.
- **Expect**: NONE exists outside the dashboard on standalone Free — this is expected absence, not a bug.
- **Action**: with BuddyPress active, repeat.
- **Expect**: only the BP nav bell shows MVS notifications; the dashboard's own bell is suppressed (no double-render) — see customer/71 for the BP-specific assertions.

### 2. Mark notifications read (MV-NTF-002)
- **Action**: with several unread notifications, click ONE notification.
- **Expect**: `POST /mvs/v1/me/notifications/read` with that one id marks only it read (and typically navigates to its target); badge count decrements by 1 from the response, no separate re-fetch.
- **Action**: click "Mark all read".
- **Expect**: `POST /mvs/v1/me/notifications/read` with empty `ids` marks every unread row read; badge clears to 0 immediately.

### 3. All 7 notification types fire, and never to yourself (MV-NTF-003)
- **Action**: from Member B acting on Member A's content/account, trigger each in turn: follow Member A (`new_follower`); react to Member A's media (`media_reaction`); comment on it (`media_comment`); @mention Member A in a comment (`media_mention`); favourite Member A's media (`media_favorite`); DM Member A (`new_message`); have a moderator resolve a report Member A filed (`report_resolved`).
- **Expect**: exactly these 7 types appear in Member A's notification list, each with copy identifying who did what and a working link to the target.
- **Action**: as Member A, react to/comment on/favourite YOUR OWN media.
- **Expect**: no self-notification is ever created.

### 4. Email notification preferences (MV-NTF-004)
- **Action**: `wp option get mvs_email_report_outcome` — confirm it defaults OFF/empty; turn "Report reviewed" ON site-wide (Settings > General > Emails).
- **Action**: with Member A's own "Email me about activity" ON, file a report as Member A and have a moderator resolve it.
- **Expect**: Member A receives an email.
- **Action**: set Member A's own "Email me about activity" to No; repeat.
- **Expect**: NO email is sent regardless of the site-wide toggle — the member-level off is absolute.
- **Action**: turn on "Photo battle invites" and "Documents shared with a member" site-wide (both captioned "(MediaVerse Pro)").
- **Expect**: on Free-only, no email is EVER sent for either, since nothing on Free raises the underlying event — this is expected, not a bug.
- **Action**: trigger an account-deletion confirmation email regardless of any of the above toggles.
- **Expect**: always sent.
- **Action**: open a delivered activity email and click its unsubscribe link.
- **Expect**: flips Member A's own "Email me about activity" preference off directly, with NO login required (`EmailService::maybe_unsubscribe()`).

### 5. Mentions (MV-NTF-005)
- **Action**: as Member B, comment "@<memberA-username> check this out" on a media item.
- **Expect**: the `@memberA` text renders as a real link in the rendered comment; Member A receives a `media_mention` notification; `GET /mvs/v1/me/mentions` (as Member A) includes it.
- **Action**: put `@<memberA-username>` in a media TITLE or DESCRIPTION (not a comment) and save.
- **Expect**: NO mention notification fires — mentions are parsed from comments only, never from titles/descriptions.

## Pass criteria

ALL of the following hold:
1. Bell + badge work on the dashboard rail on standalone Free; absent elsewhere on standalone Free; suppressed in favor of the BP bell when BuddyPress is active.
2. Clicking one notification marks only it read; "Mark all read" clears the badge to 0; both update from the response with no re-fetch.
3. All 7 notification types fire correctly and never to yourself.
4. Email sending obeys BOTH the site-wide toggle AND the member's own preference (member-off is absolute); Pro-only toggles send nothing on Free; account-deletion email always sends; the unsubscribe link works without login.
5. @mentions are parsed only from comments, never from title/description text.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A self-action generates a notification | missing actor != recipient guard | `includes/Social/NotificationService.php` |
| Both the dashboard bell and the BP bell render simultaneously | BP-active suppression check missing | `templates/partials/dashboard-content.php`, BuddyPress integration |
| Email still sent with the member's own preference off | member-level check not read before site-wide send | `includes/Services/EmailService.php` (or equivalent) |
| Unsubscribe link requires login | the no-login token path removed from `maybe_unsubscribe()` | wherever `EmailService::maybe_unsubscribe()` lives |
| Title/description text creates a mention notification | mention parser scanning more than comment content | `includes/Social/MentionService.php` |

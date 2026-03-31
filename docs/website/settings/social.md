# Social & Messaging Settings

Access these settings at **Media > Settings > Social**.

![Social and Messaging settings tab](../images/admin-settings-social.png)

These settings control the follow system, who can send direct messages, and which events trigger email notifications.

---

## Follows

| Option | Default | Description |
|--------|---------|-------------|
| Enable Follow System | Enabled | Allow users to follow each other. When disabled, the Follow button is hidden site-wide and the follow feed is not available. |

---

## Direct Messages

| Option | Key | Default | Description |
|--------|-----|---------|-------------|
| Enable Direct Messages | `mvs_dm_enabled` | Enabled | Master toggle for the entire DM system. Disabling this removes the chat panel and Message buttons from all pages. |
| Who can send me messages | `mvs_dm_access` | `followers` | Site-wide default for new accounts. Users can change this in their own account settings. Options: `everyone`, `followers` (people the recipient follows back), `mutual` (both users must follow each other), `nobody`. |
| Minimum account age to send DMs | `mvs_dm_min_age` | `0` | Number of days a user account must exist before that user can initiate new conversations. Set to `0` to disable the restriction. This does not prevent receiving messages. |
| Show online status to others | `mvs_show_online_status` | Enabled | Site-wide default. When enabled, a green dot appears next to a user's avatar in the chat panel when they are active. Users can override this in their own account settings. |

> Setting **Who can send me messages** here changes the default for all new user registrations. Existing users retain their individual setting unless you reset them via WP-CLI: `wp mvs reset-dm-access --new-value=mutual`.

![Direct Messages section of the Social settings page](../images/admin-settings-social.png)

---

## Notifications

These toggles control whether WPMediaVerse sends email notifications for social events. Transactional email delivery depends on your site's configured mailer (SMTP plugin, SendGrid, etc.).

| Event | Option | Default | Description |
|-------|--------|---------|-------------|
| New follower | `mvs_notify_follow` | Enabled | Email sent to a user when someone follows them |
| New reaction on my media | `mvs_notify_reaction` | Enabled | Email sent when another user reacts to the recipient's media |
| New comment on my media | `mvs_notify_comment` | Enabled | Email sent when someone comments on the recipient's media |
| Mention in a comment | `mvs_notify_mention` | Enabled | Email sent when a user is @mentioned in a comment |
| New direct message | `mvs_notify_dm` | Enabled | Email sent when a user receives a new direct message and has not read it within 5 minutes |

![Notifications section showing toggle switches](../images/admin-settings-social.png)

> Users cannot override the DM email notification setting individually. If you want to give users control over their own notification preferences, use the `mvs_user_notification_preferences` filter to load per-user meta.

---

## Defining Options via wp-config.php

You can lock any Social setting as a constant to prevent admin changes:

```php
// wp-config.php
define( 'MVS_DM_ACCESS', 'mutual' );         // Lock DM access to mutual followers.
define( 'MVS_DM_MIN_AGE', 7 );               // Require 7-day-old accounts to send DMs.
define( 'MVS_SHOW_ONLINE_STATUS', false );    // Disable online status site-wide.
```

When a constant is detected, the corresponding field on the settings page is shown as read-only.

# Messages Settings

Access these settings at **MediaVerse > Settings > Messages**. This tab was called "Messaging" and "Direct Messages" before 2.6.0.

![Messages settings tab](../images/admin-settings-social.png)

These settings control who can message whom, where the chat appears, and who sees online status.

---

## Messages

| Option | Key | Default | Description |
|--------|-----|---------|-------------|
| Who can send messages | `mvs_dm_access` | `everyone` | Who may start a conversation with another member. Options: Everyone (`everyone`), Followers only - others go to Requests (`followers`), Mutual followers only (`mutual`), Nobody - messages off (`nobody`). |
| Minimum Account Age (days) | `mvs_dm_min_age` | `0` | Accounts younger than this cannot send messages. 0 turns this off. |
| Chat Panel Visibility | `mvs_chat_panel_visibility` | `everywhere` | Where the floating chat icon appears for signed-in members. Options: `everywhere`, `mvs_pages` (MediaVerse pages only - Explore, Dashboard, Albums, Member Profiles), `bp_pages` (BuddyPress member and group pages only), `disabled` (never show the slide-out; use only the /messages/ page). |
| Online Status Visibility | `mvs_show_online_status` | `everyone` | Who can see that a member is online right now. Options: `everyone`, `followers`, `nobody`. |

> **Who can send messages** applies site-wide. Members cannot override it one by one.

Developers can hide the chat panel on a single request with the `mvs_should_render_chat_panel` filter - return `false` to suppress it.

---

## Setting these options with WP-CLI

Each setting is a normal WordPress option, so you can set it without the screen:

```bash
wp option update mvs_dm_access followers
wp option update mvs_dm_min_age 7
wp option update mvs_show_online_status nobody
```

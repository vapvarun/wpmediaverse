# BuddyPress Notifications

> **Included in Free** - MediaVerse is the most complete media solution for BuddyPress communities. Integration is optional - the plugin works standalone on any WordPress site, but when BuddyPress is active, it unlocks profile tabs, group media, activity stream, and notifications automatically.

When BuddyPress Notifications is active, MediaVerse sends in-app notifications for media social events.

## Notification Types

| Event | Who Gets Notified |
|-------|-------------------|
| Someone reacts to your media | Media owner |
| Someone comments on your media | Media owner |
| Someone @mentions you in a comment | Each mentioned user |
| Someone follows you | The member followed |
| Someone favourites your media | Media owner |
| A moderator reviews a report you filed | The member who filed it |
| MediaVerse Pro events, such as photo battle invites and competition results | The member concerned |

Every MediaVerse notification type is mirrored into the BuddyPress bell, except direct messages. Messages keep their own unread badge on the chat button.

MediaVerse sends each notification once. It does not listen on several separate events for the same action, which would produce duplicates.

## Notification Registration

MediaVerse registers itself as a BuddyPress notification component, so its notifications appear in the member's BuddyPress notification list and bell. The wording and link are the same as in MediaVerse's own notification list.

## Notification Format

Notifications appear in the BuddyPress notification bell with these formats:

| Type | Format |
|------|--------|
| Reaction | **Username** reacted to **[media title]** (or "to your media" when it has no title) |
| Comment | **Username** commented on **[media title]** (or "on your media") |
| Mention | **Username** mentioned you |
| Follow | **Username** started following you |
| Favourite | **Username** favorited **[media title]** (or "your media") |
| Report reviewed | A moderator reviewed your report. Thank you for helping keep the community safe. |

## Notification Filters (BP Nouveau)

In BuddyPress Nouveau, members can filter their notification list by **Media Reactions**, **Media Comments** and **Media Mentions**.

## Reading Notifications via REST API

```bash
curl https://yoursite.com/wp-json/mvs/v1/me/notifications \
  -H "X-WP-Nonce: NONCE"
```

This returns MediaVerse-specific notifications. For the full BuddyPress notification list, use the BP REST API.

## Marking Notifications as Read

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/me/notifications/read \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{"ids": [123]}'
```

## Notification Count

The frontend reads the unread count from `GET /mvs/v1/me/notifications/count`, which returns `{"count": N}` for the current user, without requiring WebSockets.

## 2.0.0 update - no double-notify on activity comments

When a media comment is posted from inside a linked BuddyPress activity (an upload shared to the activity stream), BuddyPress already fires its own native "replied to your update" notification. Before 2.0.0, MediaVerse also mirrored its own `media_comment` notification for the same comment, so the media owner saw two bell entries for one comment.

`NotificationIntegration` now detects this case (a comment whose media has a linked `bp_activity_id` that BuddyPress itself will notify on) and skips the BP-mirrored `media_comment` notification. The native MVS in-app notification is unaffected - `GET /mvs/v1/me/notifications` and any REST/app client still see it; only the duplicate BuddyPress bell entry is suppressed.

Restore the old double-notify behavior with a filter, if a site wants both:

```php
add_filter( 'mvs_suppress_bp_comment_notification', '__return_false' );
```

See [`mvs_suppress_bp_comment_notification`](../developer-guide/hooks-filters.md#mvs_suppress_bp_comment_notification-new-in-200) in the hooks reference.

## 1.2.0 update - single notification surface

When BuddyPress Notifications is active, MediaVerse notifications are mirrored to BuddyPress, and the standalone MediaVerse dashboard bell is hidden. This means BP-active sites see one bell - the BP nav bell - instead of two competing bells rendering the same notifications.

This is automatic. No setting to flip, no filter to add. If BuddyPress is deactivated, the standalone MediaVerse bell returns automatically.

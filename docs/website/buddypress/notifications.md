# BuddyPress Notifications

> **Included in Free** - WPMediaVerse is the most complete media solution for BuddyPress communities. Integration is optional - the plugin works standalone on any WordPress site, but when BuddyPress is active, it unlocks profile tabs, group media, activity stream, and notifications automatically.


When BuddyPress Notifications is active, WPMediaVerse sends in-app notifications for media social events.

## Notification Types

| Event | NotificationService Type | BP Component Action | Who Gets Notified |
|-------|--------------------------|---------------------|-------------------|
| Someone reacts to your media | `media_reaction` | `mvs_new_reaction` | Media owner |
| Someone comments on your media | `media_comment` | `mvs_new_comment` | Media owner |
| Someone @mentions you in a comment | `media_mention` | `mvs_new_mention` | Each mentioned user |

`NotificationIntegration` subscribes to the single `mvs_notification_created` signal emitted by `NotificationService::create()` and mirrors these three types into BuddyPress via `bp_notifications_add_notification()`. It does not listen on raw plugin hooks (which previously caused duplicate notifications).

## Notification Registration

WPMediaVerse registers `wpmediaverse` as a BuddyPress notification component via the `bp_notifications_get_registered_components` filter.

Notification format strings are registered via:

```php
add_filter( 'bp_notifications_get_notifications_for_user', ... );
```

## Notification Format

Notifications appear in the BuddyPress notification bell with these formats:

| Type | Format |
|------|--------|
| Reaction | **Username** reacted to your media |
| Comment | **Username** commented on your media |
| Mention | **Username** mentioned you |

![BuddyPress notification dropdown showing WPMediaVerse notifications](../images/bp-profile-media.jpg)

## Notification Filters (BP Nouveau)

In BuddyPress Nouveau, notification filter links are registered via `bp_nouveau_notifications_init_filters` to allow users to filter their notification list by WPMediaVerse notifications.

## Reading Notifications via REST API

```bash
curl https://yoursite.com/wp-json/mvs/v1/me/notifications \
  -H "X-WP-Nonce: NONCE"
```

This returns WPMediaVerse-specific notifications. For the full BuddyPress notification list, use the BP REST API.

## Marking Notifications as Read

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/me/notifications/read \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{"ids": [123]}'
```

## Notification Count

The frontend reads the unread count from `GET /mvs/v1/me/notifications/count`, which returns `{"count": N}` for the current user, without requiring WebSockets.

## 1.2.0 update - single notification surface

When BuddyPress is active, every WPMediaVerse notification is mirrored to BuddyPress via `bp_notifications_add_notification`, and the standalone dashboard `.mvs-notification-bell` markup is suppressed. This means BP-active sites see one bell - the BP nav bell - instead of two competing bells rendering the same notifications.

This is automatic. No setting to flip, no filter to add. If BuddyPress is deactivated, the standalone WPMediaVerse bell returns automatically.

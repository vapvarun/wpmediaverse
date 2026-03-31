# BuddyPress Notifications

> **Included in Free** — WPMediaVerse is the most complete media solution for BuddyPress communities. Integration is optional — the plugin works standalone on any WordPress site, but when BuddyPress is active, it unlocks profile tabs, group media, activity stream, and notifications automatically.


When BuddyPress Notifications is active, WPMediaVerse sends in-app notifications for media social events.

## Notification Types

| Event | Action Hook | Who Gets Notified |
|-------|-------------|-------------------|
| Someone reacts to your media | `mvs_reaction_added` | Media owner |
| Someone comments on your media | `mvs_comment_created` | Media owner |
| Someone @mentions you in a comment | `mvs_mentions_created` | Each mentioned user |

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
| Reaction | **Username** reacted to your photo **[media title]** |
| Comment | **Username** commented on your media **[media title]** |
| Mention | **Username** mentioned you in a comment on **[media title]** |

![BuddyPress notification dropdown showing WPMediaVerse notifications](../images/bp-profile-media.jpg)

## Notification Filters (BP Nouveau)

In BuddyPress Nouveau, notification filter links are registered via `bp_nouveau_notifications_init_filters` to allow users to filter their notification list by WPMediaVerse notifications.

## Reading Notifications via REST API

```bash
curl https://yoursite.com/wp-json/mvs/v1/notifications \
  -H "X-WP-Nonce: NONCE"
```

This returns WPMediaVerse-specific notifications. For the full BuddyPress notification list, use the BP REST API.

## Marking Notifications as Read

```bash
curl -X PUT https://yoursite.com/wp-json/mvs/v1/notifications/123 \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{"is_new": false}'
```

## Real-Time Notification Polling

WPMediaVerse includes a REST polling transport (`RestPollingTransport`) that the frontend uses to poll `/mvs/v1/notifications` for new notifications without requiring WebSockets.

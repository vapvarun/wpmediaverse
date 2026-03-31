# Privacy & Access Control

> **Free + Pro** — Core functionality is included free. Features marked with **(Pro)** require WPMediaVerse Pro.


WPMediaVerse provides 6 privacy levels for media items, albums, and collections. Access checks run on every REST API call and on the explore archive query.

## Privacy Levels

| Level | Value | Who Can View |
|-------|-------|-------------|
| Public | `public` | Everyone, including logged-out visitors |
| Members Only | `members` | Any logged-in WordPress user |
| Friends | `friends` | BuddyPress friends of the media owner (requires BuddyPress active) |
| Group | `group` | Members of a specific BuddyPress group (requires BuddyPress active) |
| Private | `private` | Only the media owner and users with `moderate_mvs_media` |
| Custom | `custom` | A specific list of user IDs defined via access grants |

## Owners and Moderators Always Have Access

Media owners (the `post_author`) and users with the `moderate_mvs_media` capability bypass all privacy checks. They can view all media regardless of its privacy level.

## Setting Privacy on Upload

Set the `privacy` field when creating media via REST API:

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/media \
  -H "X-WP-Nonce: NONCE" \
  -F "file=@photo.jpg" \
  -F "privacy=friends"
```

For group privacy, also include `group_id`:

```bash
  -F "privacy=group" \
  -F "group_id=42"
```

## Changing Privacy After Upload

```bash
curl -X PUT https://yoursite.com/wp-json/mvs/v1/media/123 \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{"privacy": "private"}'
```

## Custom Access Grants

For `custom` privacy, grant access to specific users:

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/media/123/access \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 55,
    "expires_at": "2026-01-01T00:00:00Z"
  }'
```

Access grants can have optional expiry dates. Expired grants are cleaned up via `wp mvs cleanup-expired` or via cron.

## Signed URLs for Private Files

For media stored with a non-public privacy level, WPMediaVerse can generate time-limited signed URLs:

```bash
curl https://yoursite.com/wp-json/mvs/v1/media/123/signed-url \
  -H "X-WP-Nonce: NONCE"
```

Response:
```json
{
  "url": "https://yoursite.com/wp-content/uploads/wpmediaverse/2025/03/photo.jpg?token=abc123&expires=1743000000",
  "expires_at": "2025-03-27T13:00:00Z"
}
```

The signed URL TTL defaults to 3600 seconds (1 hour) and is configurable in **Media > Settings > General > Signed URL Expiry**.

## Filtering Privacy Access in Code

Use the `mvs_privacy_can_view` filter to extend or override access logic:

```php
add_filter( 'mvs_privacy_can_view', function( $result, $media_id, $user_id, $privacy ) {
    // Grant access to premium subscribers regardless of privacy level.
    if ( null === $result && wcs_user_has_subscription( $user_id, '', 'active' ) ) {
        return true;
    }
    return $result;
}, 10, 4 );
```

Return `null` to let the built-in logic run. Return `true` or `false` to override it.

## Explore Archive Privacy Filtering

On the explore archive (`/media/`), WPMediaVerse applies automatic privacy filtering via a `posts_where` filter:

- **Logged-out users** see only `public` media.
- **Logged-in non-moderators** see `public`, `members` media, and their own media (any privacy level).
- **Moderators** (`moderate_mvs_media` capability) see all media.

# Advanced Privacy

> **Requires MediaVerse Pro** - This feature is available exclusively in the Pro version.



MediaVerse Pro extends the free plugin's privacy levels with album-level inheritance, per-user presets, and bulk privacy updates.

![Privacy selector on the upload page showing all six levels](../images/upload-page.png)

## Privacy Levels

The six levels available in both free and Pro versions:

| Level | Value | Who Can View |
|-------|-------|-------------|
| Public | `public` | Everyone including logged-out visitors |
| Members Only | `members` | Any logged-in WordPress user |
| Friends | `friends` | BuddyPress friends of the owner (requires BuddyPress) |
| Group | `group` | Members of a specific BuddyPress group (requires BuddyPress) |
| Private | `private` | Only the owner and users with `moderate_mvs_media` |
| Custom | `custom` | A defined list of user IDs via access grants |

Pro adds multi-level inheritance, presets, and bulk management on top of these levels.

---

## Album-Level Inheritance

A photo in an album shows with the album's privacy, and a photo in no album with its own. This is a **Free** feature - see the Albums documentation.

Since 2.6.0 the album applies in both directions (making an album public again brings its photos back), each photo belongs to one album, and a photo's own privacy is kept and restored when it leaves the album. Before 2.6.0 the album only ever tightened an item and the item's original setting was lost.

Site owners can opt out with the `mvs_album_inherit_privacy` filter:

```php
add_filter( 'mvs_album_inherit_privacy', '__return_false' );
```json
{
  "privacy": "group",
  "group_id": 42
}
```

`group_id` is required when `privacy` is `group`.

**Response:** `200 OK` with the updated privacy object.

### POST /media/bulk-privacy

Update privacy for multiple media items in one request. Requires the user to be logged in; items the user does not own are skipped. Accepts up to 100 media IDs per request.

**Body:**

```json
{
  "media_ids": [101, 102, 103],
  "privacy": "private"
}
```

Items the authenticated user does not own are skipped. The response lists which IDs were updated and which were skipped.

**Response:**

```json
{
  "updated": [101, 102],
  "skipped": [103]
}
```

### GET /privacy/presets

List the current user's saved privacy presets.

**Response:** an array of presets.

```json
[
  {
    "id": "mvs_preset_66f1c2a4e1b2c3.12345678",
    "name": "Close friends only",
    "privacy": "custom",
    "custom_users": [12, 45],
    "created_at": 1790000000
  }
]
```

### POST /privacy/presets

Save a new privacy preset for the current user. A member can keep up to 20.

**Body:**

```json
{
  "name": "Close friends only",
  "privacy": "custom",
  "custom_users": [12, 45]
}
```

`custom_users` is only used when `privacy` is `custom`.

**Response:** `201 Created` with the new preset object.

---

## Bulk Privacy Changes

Members change the privacy of many items at once from **My Media**: select items, pick a privacy level in the bar that appears, and apply. Photos that sit in an album keep the album's privacy until they leave it (the bar says how many).

Apps and integrations use `POST /media/bulk-privacy` above. There is no bulk privacy action on the wp-admin media list.

---

## User Privacy Presets

Presets are saved privacy choices, such as a "close friends" list, that the mobile app or an integration can offer again. They are created and listed through the REST API above; the site's own upload form does not show them.

## Developer Filter

Use `mvs_privacy_can_view` (available in the free plugin) to extend access logic. Pro privacy checks run through the same filter:

```php
add_filter( 'mvs_privacy_can_view', function( $result, $media_id, $user_id, $privacy ) {
    // Grant access to users with a custom capability.
    if ( null === $result && user_can( $user_id, 'mvs_vip_access' ) ) {
        return true;
    }
    return $result;
}, 10, 4 );
```

Return `null` to let built-in logic run. Return `true` or `false` to override it.

# Advanced Privacy

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro extends the free plugin's privacy levels with album-level inheritance, per-user presets, and bulk privacy updates.

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

Media added to a non-public album inherits the album's privacy. This is a **Free** feature as of 2.3.0 — see the Albums documentation.

The privacy of an item is clamped to the more restrictive of (item, album) at the moment it is added to the album. The clamp only tightens; it never loosens an item that is already more restrictive.

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

**Response:**

```json
{
  "presets": [
    {
      "id": 1,
      "name": "Close friends only",
      "privacy": "friends",
      "is_default": true
    }
  ]
}
```

### POST /privacy/presets

Save a new privacy preset for the current user.

**Body:**

```json
{
  "name": "Close friends only",
  "privacy": "friends",
  "is_default": true
}
```

Setting `is_default` to `true` makes this preset the pre-selected option on the upload form. Only one preset can be the default; saving a new default clears the flag from the previous one.

**Response:** `201 Created` with the new preset object.

---

## Bulk Privacy Updates

Site administrators can update privacy in bulk from **Media > All Media**:

1. Select media items using the checkboxes.
2. Open the **Bulk Actions** dropdown.
3. Select **Change Privacy**.
4. Choose the target privacy level and click **Apply**.

![Media list table with bulk actions dropdown](../images/admin-media-list.png)

This uses the same `POST /media/bulk-privacy` endpoint internally and respects the same ownership rules.

---

## User Privacy Presets

Users can save their preferred privacy level as a named preset from the upload page. The preset they mark as default is automatically selected each time they open the upload form.

Presets are stored as user meta. They are personal and not visible to other users or administrators.

![Upload form showing preset selector dropdown](../images/upload-page.png)

---

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

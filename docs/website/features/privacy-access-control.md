# Privacy & Access Control

> **Free + Pro** - Core functionality is included free. Features marked with **(Pro)** require MediaVerse Pro.


MediaVerse provides privacy levels for media items, albums, and collections. Access checks run on every REST API call and on the explore archive query.

The six levels below are the ones the upload form offers. The full vocabulary a write is allowed to store is `PrivacyService::supported_levels()` - as of 2.4.0 `public`, `members`, `loggedin`, `friends`, `group`, `space`, `private`, `dm` and `custom`, filterable through `mvs_privacy_levels`. A level not in that list is refused at the edge rather than stored and silently ignored.

## Privacy Levels

| Level | Value | Who Can View |
|-------|-------|-------------|
| Public | `public` | Everyone, including logged-out visitors |
| Members Only | `members` | Any logged-in WordPress user |
| Friends | `friends` | BuddyPress friends of the media owner (requires BuddyPress active) |
| Group | `group` | Members of a specific BuddyPress group (requires BuddyPress active) |
| Private | `private` | Only the media owner and users with `moderate_mvs_media` |
| Custom | `custom` | A specific list of user IDs defined via access grants |

Pro **documents** add one more level, **this space** (`space`) - visible to members of the Space the document's drive belongs to. It applies to documents; media items are not space-scoped by default, though a document (or media) can be bound to a Space drive with the `mvs_media_drive` filter.

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
curl -X POST https://yoursite.com/wp-json/mvs/v1/media/123/grant \
  -H "X-WP-Nonce: NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 55,
    "expires_at": "2026-01-01T00:00:00Z"
  }'
```

Access grants can have optional expiry dates. Expired grants are cleaned up via `wp mvs cleanup-expired` or via cron.

## How Media Files Are Delivered

Since 2.6.1, photos, video and audio are sent straight by your web server, without loading WordPress for each file. This keeps busy pages fast and uses far fewer PHP workers.

Each stored file has a long random name (16 random characters) that cannot be guessed, and MediaVerse only gives the address to people allowed to see the item. When an item becomes more private (for example Public to Members, or Members to Only me), or is trashed, flagged or rejected, its files get new names, so any address shared earlier stops working. Making an item more public keeps its names.

Some files always go through a permission check on every request, using a time-limited signed link (`/wp-json/mvs/v1/serve`):

- message (DM) attachments
- documents and SVG images
- downloads, so download counts and file names stay correct
- files saved under a readable name, if the site chose to keep original file names (the `mvs_filename_strategy` option)

Files saved before 2.6.1 under readable names (older video covers, imported files, uploads kept under their own names) are given random names automatically after the update, in the background, newest first. Nothing is needed from you, and links shared earlier keep working. Until an item is converted it keeps using signed links. Deleting a video now also removes its cover's source image, which older versions left on disk.

### It turns itself on only when it is safe

After updating, MediaVerse checks once (on the next admin page load, then daily) that your web server sends a random-named test file from `wp-content/uploads/wpmediaverse/`. Until that check passes, every file keeps using signed links, exactly as before. Existing links keep working either way.

- **Apache and LiteSpeed:** MediaVerse updates the folder's `.htaccess` so only random-named media files are served directly; everything else stays blocked. A `.htaccess` you edited yourself is never changed.
- **nginx:** nginx does not read `.htaccess`. **Tools > Site Health** shows the rule to add to your server configuration when it is needed, for example when an older MediaVerse rule blocks the whole folder.

To keep every file on signed links, add `add_filter( 'mvs_direct_media_delivery', '__return_false' );` to a small plugin.

### Signed link lifetime

Signed links last 1 hour by default (**Media > Settings > Storage > Signed URL Expiry (seconds)**). Links for non-public media stay the same for half of that time, so browsers can reuse a downloaded file instead of fetching it again.

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

On the explore archive (`/media/`), MediaVerse applies automatic privacy filtering through a SQL clause on its own `mvs_media_index` table (a `privacy IN (...) OR post_author = current` gate shared by every explore surface), not a WordPress `posts_where` filter:

- **Logged-out users** see only `public` media.
- **Logged-in non-moderators** see `public`, `members` media, and their own media (any privacy level).
- **Moderators** (`moderate_mvs_media` capability) see all media.

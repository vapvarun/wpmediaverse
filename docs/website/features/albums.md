# Albums

Albums are ordered collections of media items stored as `mvs_album` custom post type entries. They let users group related media into a single viewable set.

[screenshot: Album viewer showing a grid of photos with album title and description]

## Creating an Album

**Via the frontend REST API:**

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/albums \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Summer 2025",
    "description": "Photos from our trip",
    "privacy": "public"
  }'
```

**Via WP Admin:** Go to **Media > Albums > Add New Album**.

## Adding Media to Albums

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/albums/ALBUM_ID/items \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{"media_ids": [101, 102, 103]}'
```

This fires the `mvs_album_items_added` action, which triggers BuddyPress activity updates if BuddyPress is active.

## Displaying an Album

**Gutenberg Block:** Add the **WPMediaVerse: Album Viewer** block, then select an album from the block settings.

**Shortcode:**
```
[mvs_album id="123"]
[mvs_album id="123" columns="4" show_title="true" show_description="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Album post ID |
| `columns` | `3` | Grid columns to display |
| `show_title` | `true` | Show the album title above the grid |
| `show_description` | `true` | Show the album description |

## Album Privacy

Albums have their own privacy level independent of the media items they contain. If a user can see the album but not a specific media item (because the item's privacy is more restrictive), that item is hidden from the album view.

## REST API Endpoints for Albums

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs/v1/albums` | List albums |
| `POST` | `/mvs/v1/albums` | Create album |
| `GET` | `/mvs/v1/albums/{id}` | Get album |
| `PUT` | `/mvs/v1/albums/{id}` | Update album |
| `DELETE` | `/mvs/v1/albums/{id}` | Delete album |
| `GET` | `/mvs/v1/albums/{id}/items` | List album media |
| `POST` | `/mvs/v1/albums/{id}/items` | Add media to album |
| `DELETE` | `/mvs/v1/albums/{id}/items/{media_id}` | Remove media from album |

## BuddyPress Activity

When media is added to an album and BuddyPress is active, the `mvs_album_items_added` action updates the upload activity item to reference the album. This replaces the generic "uploaded media" activity with "uploaded media to [Album Name]".

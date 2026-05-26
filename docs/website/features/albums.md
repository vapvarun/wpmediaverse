# Albums

> **Included in Free** - This feature is available in the free version of WPMediaVerse.


Group your photos into beautiful collections - tell a story, document a trip, or organize your portfolio with a single shareable album.

## What You Can Do

- Create albums to organize related photos and videos together
- Add photos from your existing media library to any album
- Set a cover photo that represents the album
- Control album privacy independently from individual photo privacy
- Share albums with friends or keep them private
- Embed any album on a page using a block or shortcode

## How It Works (for Users)

1. Go to your media dashboard and click **Create Album**
2. Give your album a title and optional description
3. Choose a privacy level: public, members only, friends, or private
4. Click **Add Media** to pick photos from your uploads - select as many as you like
5. Drag photos in the album to reorder them. Click the star on any photo to set it as the cover
6. Click **Save Album** - your album is live and appears on your profile
7. To share your album, copy the album link from the album page and send it to anyone

![Album creation form with title field and privacy selector](../images/dashboard-media.png)

## For Site Owners

1. Albums are enabled by default once WPMediaVerse is activated
2. To embed a specific album on a page, use the **WPMediaVerse: Album Viewer** block in the block editor - select the album from the block sidebar
3. Or use the shortcode `[mvs_album id="123"]` where `123` is the album's post ID
4. Users manage their own albums from their media dashboard
5. Admins can view and delete any album from **Media > Albums** in wp-admin

## Album Privacy

Albums have their own privacy level independent of the media items they contain. If a user can see the album but not a specific media item (because the item's privacy is more restrictive), that item is hidden from the album view.

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

### Creating an Album via API

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

### Adding Media to Albums via API

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/albums/ALBUM_ID/items \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{"media_ids": [101, 102, 103]}'
```

This fires the `mvs_album_items_added` action, which triggers BuddyPress activity updates if BuddyPress is active.

## BuddyPress Activity

When media is added to an album and BuddyPress is active, the `mvs_album_items_added` action updates the upload activity item to reference the album. This replaces the generic "uploaded media" activity with "uploaded media to [Album Name]".

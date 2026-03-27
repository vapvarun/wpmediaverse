# Collections

Collections are curated or smart-generated sets of media items stored as the `mvs_collection` custom post type. Unlike albums (which have a fixed item list), collections can be **manual** (user-curated) or **smart** (auto-populated by rules like tags or categories).

[screenshot: Collection page showing a grid with the collection title and smart/manual badge]

## Collection Types

| Type | Description |
|------|-------------|
| **Manual** | The owner adds specific media IDs to the collection. Items are stored in the `wp_mvs_favorites` table with the collection ID. |
| **Smart** | Rules-based. The collection resolves its item list dynamically at request time based on criteria like tag, category, media type, or date range. |

## Creating a Collection

**Via REST API:**

```bash
curl -X POST https://yoursite.com/wp-json/mvs/v1/collections \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Nature Photos",
    "type": "smart",
    "rules": {
      "tags": ["nature", "landscape"],
      "media_type": "image"
    },
    "privacy": "public"
  }'
```

## Displaying a Collection

**Shortcode:**
```
[mvs_collection id="456"]
[mvs_collection id="456" columns="3" per_page="20"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Collection post ID |
| `columns` | `3` | Grid columns to display |
| `per_page` | `20` | Maximum number of media items to show |

If no `id` is provided, the shortcode outputs an error message. If the collection post is not published, the shortcode shows "Collection not found."

## REST API Endpoints for Collections

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs/v1/collections` | List collections |
| `POST` | `/mvs/v1/collections` | Create collection |
| `GET` | `/mvs/v1/collections/{id}` | Get collection with resolved items |
| `PUT` | `/mvs/v1/collections/{id}` | Update collection |
| `DELETE` | `/mvs/v1/collections/{id}` | Delete collection |

## Collection Meta Box

In the WordPress admin, each `mvs_collection` post has a **Collection Settings** meta box that lets you set the collection type and define smart rules without using the API.

[screenshot: Collection meta box in WordPress admin showing type toggle and rules fields]

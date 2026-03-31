# Shortcodes

> **Included in Free** — This feature is available in the free version of WPMediaVerse.


WPMediaVerse provides 8 shortcodes for embedding media features in pages, posts, and classic editor content.

## [mvs_gallery]

Displays a filterable media grid. Columns and items-per-page come from **Media > Settings > Display** and cannot be overridden by shortcode attributes.

```
[mvs_gallery]
[mvs_gallery type="image" category="nature" tag="summer" orderby="date"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `type` | (all types) | Filter by media type: `image`, `video`, or `audio` |
| `category` | (all) | Filter by `mvs_category` slug |
| `tag` | (all) | Filter by `mvs_tag` slug |
| `orderby` | `date` | Sort order: `date`, `title`, or `views` |

## [mvs_upload]

Displays the frontend file upload form. The form is only functional for logged-in users.

```
[mvs_upload]
[mvs_upload max_files="5" show_privacy="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `max_files` | `10` | Maximum number of files selectable at once |
| `show_privacy` | `true` | Show privacy level selector on the form |

This shortcode fires the `mvs_before_upload_form` action before rendering.

## [mvs_album]

Displays a single album by ID.

```
[mvs_album id="123"]
[mvs_album id="123" columns="4" show_title="true" show_description="false"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Album post ID |
| `columns` | `3` | Grid columns |
| `show_title` | `true` | Show album title |
| `show_description` | `true` | Show album description |

## [mvs_player]

Embeds a single media item in an interactive player.

```
[mvs_player id="456"]
[mvs_player id="456" autoplay="false" loop="false" download="false"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Media post ID |
| `autoplay` | `false` | Start playback automatically (audio/video) |
| `loop` | `false` | Loop the media after it finishes |
| `download` | `false` | Show a download button |

## [mvs_stats]

Displays site-wide media statistics.

```
[mvs_stats]
[mvs_stats views="true" downloads="true" reactions="true" top="true" top_count="5"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `views` | `true` | Show total view count |
| `downloads` | `true` | Show total download count |
| `reactions` | `true` | Show total reaction count |
| `top` | `true` | Show the top media list |
| `top_count` | `5` | Number of items in the top media list |

## [mvs_dashboard]

Displays a personal media dashboard for the logged-in user. Shows the user's own uploads, albums, and stats. Redirects to login if the user is not logged in.

```
[mvs_dashboard]
```

No configurable attributes. The dashboard uses the `dashboard-view` Interactivity API block store.

## [mvs_collection]

Displays a collection by ID. Works for both manual and smart collections.

```
[mvs_collection id="789"]
[mvs_collection id="789" columns="3" per_page="20"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Collection post ID |
| `columns` | `3` | Grid columns |
| `per_page` | `20` | Maximum items to show |

## [mvs_profile_edit]

Displays a profile edit form for the logged-in user, allowing them to update their first name, last name, display name, bio, and avatar. Redirects to login if not logged in.

```
[mvs_profile_edit]
```

No configurable attributes. The form is powered by the `mvs/profile-edit` Interactivity API store and saves to `/mvs/v1/profile`.

![Profile edit form with avatar upload and name fields](../images/profile-own.png)

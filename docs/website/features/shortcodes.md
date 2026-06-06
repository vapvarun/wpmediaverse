# Shortcodes

> **Included in Free** - This feature is available in the free version of WPMediaVerse.


WPMediaVerse provides **12** shortcodes for embedding media features in pages, posts, and classic editor content.

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
| `user_id` | (all authors) | Filter to a single author. Pair with `orderby="popular"` for a "Top media by this member" embed. |
| `orderby` | `date` | Sort order: `date`, `title`, `views`, `popular`, `reactions`, or `random`. |
| `order` | `desc` | Sort direction: `asc` or `desc`. |

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

## [mvs_explore_feed]

Embeds the explore archive - infinite-scroll public media feed with filter chips and the new search autocomplete dropdown. Use this when you want the explore experience on a custom page rather than the dedicated archive route.

```
[mvs_explore_feed]
[mvs_explore_feed layout="grid" columns="3" per_page="12" filters="true" search="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `layout` | `grid` | Feed layout. |
| `columns` | `3` | Grid columns. |
| `per_page` | `12` | Items per page. |
| `filters` | `true` | Show the filter chips. |
| `search` | `true` | Show the search input with autocomplete. |

## [mvs_lock_overlay]

Renders a privacy lock overlay for a single media item. If the current user has access, the overlay falls through and renders the player or image inline. If they do not, the overlay shows the configured restriction message.

```
[mvs_lock_overlay id="456"]
[mvs_lock_overlay id="456" blur="20" overlay_opacity="60" unlock_label="Restricted Content"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Media post ID to evaluate access against. |
| `blur` | `20` | Blur amount applied to the locked preview. |
| `overlay_opacity` | `60` | Overlay opacity (0–100). |
| `unlock_label` | (empty) | Custom restriction label. |

## [mvs_member_photos]

Renders a member's media grid. Auto-resolves the user - explicit `user_id` attribute first, then the BuddyPress displayed user, then the post author, then the current user - so the same shortcode works on profile pages, member-specific landing pages, and author archives.

> Added in 1.2.0.

```
[mvs_member_photos]
[mvs_member_photos user_id="42" columns="3" per_page="12" type="image" show_header="true" actions="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `user_id` | (auto-detect) | Force a specific user. Leave empty to use the four-step resolution chain. |
| `columns` | `3` | Grid columns. |
| `per_page` | `12` | Items per page. |
| `type` | (all types) | Filter by media type: `image`, `video`, or `audio`. |
| `show_header` | `true` | Show the member header above the grid. |
| `actions` | `true` | Show per-item action controls. |

## [mvs_pdf_viewer]

Embeds a PDF using the browser-native viewer (the `#view=FitH` URL fragment). No third-party JS, no licensing concerns.

> Added in 1.2.0.

```
[mvs_pdf_viewer id="123"]
[mvs_pdf_viewer id="123" height="800" toolbar="false"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | (required) | Media ID of the PDF. |
| `height` | `600` | Viewer height in pixels. Range: 200–1400. |
| `toolbar` | `true` | Show or hide the browser PDF toolbar. `true` to show, `false` to hide. |

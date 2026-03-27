# Display Settings

Access these settings at **Media > Settings > Display**.

[screenshot: Display settings tab showing grid and thumbnail options]

## Media Display Section

| Option | Default | Description |
|--------|---------|-------------|
| Grid Columns | 3 | Number of columns in the media grid. Applies to `[mvs_gallery]`, the Media Grid block, and the explore archive. Options: 2, 3, 4 columns. |
| Items Per Page | 12 | Number of media items loaded per page. Options: 12, 24, 48. |
| Thumbnail Style | Square (cropped) | Controls the aspect ratio of grid thumbnails. **Square** crops images to a 1:1 ratio. **Original proportions** preserves the file's native aspect ratio. |

## How Display Settings Interact with Shortcodes

The `[mvs_gallery]` shortcode deliberately uses the backend settings for **Grid Columns** and **Items Per Page** rather than letting shortcode attributes override them. This ensures consistent display across your site.

The `[mvs_album]` shortcode allows you to override the column count directly:

```
[mvs_album id="123" columns="4"]
```

## Thumbnail Generation

WPMediaVerse generates thumbnails for uploaded images using WordPress's built-in image editor. Thumbnails are stored alongside the original file in the `wpmediaverse/YYYY/MM/` upload directory.

For video files, a placeholder thumbnail is displayed.

## WPMediaVerse Pro: Additional Display Options

WPMediaVerse Pro adds a **Watermark** section to this tab, allowing you to overlay a text or image watermark on downloaded media files. The free version includes a basic watermark option under the General tab.

# Display Settings

Access these settings at **Media > Settings > Display**.

![Display settings tab showing grid and thumbnail options](../images/admin-settings-display.png)

## Media Display Section

| Option | Default | Description |
|--------|---------|-------------|
| Grid Columns | 3 | Number of columns in the media grid. Applies to `[mvs_gallery]`, the Media Grid block, and the explore archive. Options: 2, 3, 4 columns. |
| Items Per Page | 12 | Number of media items loaded per page. Options: 12, 24, 48. |
| Thumbnail Style | Square (cropped) | Controls the aspect ratio of grid thumbnails. **Square** crops images to a 1:1 ratio. **Original proportions** preserves the file's native aspect ratio. |
| Allow Downloads | On | Master toggle for the lightbox **Download** button. When off, the button is hidden site-wide and the `/mvs/v1/media/{id}/download` REST endpoint refuses requests. Per-media Allow Downloads (set in the per-media Edit modal) is still honoured when this master toggle is on. |

## Lightbox Toolbar

The full-screen lightbox includes a toolbar with three quick-action buttons.

| Button | Behaviour |
|--------|-----------|
| Download | Streams the original file to the user and increments the `mvs_media_stats.downloads` counter. Hidden when **Allow Downloads** is off (either site-wide or per-media). Rate-limited to 30 requests per minute per user. |
| Fullscreen | Expands the lightbox to fill the viewport using the browser Fullscreen API. Also bound to the `F` keyboard shortcut. |
| Share | Uses `navigator.share` where supported, falls back to copying the URL to the clipboard, falls back to a toast error. No `window.prompt()` fallback. |

All three buttons carry `aria-label` text and `:focus-visible` outlines for keyboard users.

![Lightbox with toolbar showing download, fullscreen, share, and reaction controls](../images/lightbox.png)

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

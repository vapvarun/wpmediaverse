# Display Settings

Access these settings at **WPMediaVerse > Settings > Display**.

![Display settings tab showing grid and thumbnail options](../images/admin-settings-display.png)

## Media Display Section

| Option | Default | Description |
|--------|---------|-------------|
| Grid Columns | 3 | Number of columns in the media grid. Applies to `[mvs_gallery]`, the Media Grid block, and the explore archive. Options: 2, 3, 4, 5 columns. |
| Items Per Page | 12 | Number of media items loaded per page. Options: 12, 24, 48. |
| Thumbnail Style | Original proportions (masonry) | Controls the aspect ratio of grid thumbnails. **Square** crops images to a 1:1 ratio. **Original proportions** preserves the file's native aspect ratio, packed into a masonry layout. The default flipped from Square to Original proportions in 1.8.0; restore the old square-crop default site-wide with the `mvs_default_thumbnail_style` filter. |
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

For video files, a poster is extracted from the first frame (ffmpeg, with a getID3 cover-atom fallback). As of 1.8.0, every grid, feed, and layout (My Media, Explore, the explore-feed block, and the Pinterest/Flickr/Dribbble/Instagram layouts, including Load More) shows this real first-frame poster instead of a generic video placeholder icon.

## Watermarking: Free vs Pro

WPMediaVerse Free ships the watermark **engine**: `WatermarkService` resolves whether an upload should be stamped and fires the `mvs_watermark_stamp_file` filter at upload time and at file-replace time (so there's no bypass via the replace endpoint), before any thumbnail or WebP/AVIF variant is cut. The underlying option schema (watermark type, text, logo, position, opacity) also ships in Free with safe defaults, all off by default.

What Free does **not** include is the Settings UI to configure those options, or the GD code that actually draws the mark into the image. **WPMediaVerse Pro** adds both: the **Watermark** section on **Media > Settings > Display**, where you pick Enable Watermark, Apply to (all uploads or specific roles), Watermark Type (Text or Image), Watermark Text/Image, Position, Opacity, and Text Size/Color - and the renderer that hooks `mvs_watermark_stamp_file` to draw it. Without Pro active, the engine has nothing registered to draw with, so uploads are never watermarked.

# Watermarking

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.

WPMediaVerse Pro stamps a text or logo watermark onto uploaded images using PHP's GD library, so your brand or credit travels with every photo members share.

![Watermark settings panel showing text and logo options](../images/admin-settings-display.png)

## How it works (read this first)

The watermark is **baked into the image at upload time** - it is composited directly onto the stored file, so **everyone who views the image sees it**, on the page, in the feed, and in any download.

Because of that, three things are important to understand before you turn it on:

- **It is permanent.** The watermark alters the stored image. There is no separate "clean" copy kept, and no un-watermark step. Turning the setting off later does not remove the watermark from images that were already uploaded.
- **It applies to new uploads only.** Existing media is not re-stamped, and changing the text, logo, position, or opacity later only affects images uploaded after the change.
- **Images only.** Video and audio are never watermarked.

If you need the original files kept pristine, do not enable watermarking - keep your own unwatermarked copies before uploading.

## Requirements

- PHP GD extension enabled (verify with `phpinfo()` - look for the `gd` section). If GD is unavailable the watermark is not applied and the failure is recorded on **Media > Logs**.
- For logo watermarks: a PNG file with a transparent background is recommended.

## Enabling watermarks

Go to **Media > Settings > Display > Image Watermarking** and configure the options below.

| Option | Option Key | Default | Description |
|--------|-----------|---------|-------------|
| Enable Watermark | `mvs_watermark_enabled` | `0` | Stamp a watermark onto uploaded images |
| Apply to | `mvs_watermark_apply` | `all` | `all` = every uploaded image; `roles` = only images uploaded by the roles selected below |
| Watermark uploads from | `mvs_watermark_roles` | (none) | The uploader roles to watermark. Only used when **Apply to** is `roles`; with none selected, nothing is watermarked |
| Watermark Type | `mvs_watermark_type` | `text` | `text` to overlay a text string, `image` to overlay a logo |
| Watermark Text | `mvs_watermark_text` | site title | The text to overlay (used when type is `text`); defaults to your site name. Supports the `{site}` and `{username}` tokens |
| Watermark Image | `mvs_watermark_image_id` | `0` | WordPress attachment ID of the logo image (used when type is `image`) |
| Position | `mvs_watermark_position` | `bottom-right` | Where on the image to place the watermark |
| Opacity | `mvs_watermark_opacity` | `40` | Watermark opacity, 0 (transparent) to 100 (opaque) |
| Text Size | `mvs_watermark_font_size` | `24` | Base font size for text watermarks (px); scales with the image |
| Text Colour | `mvs_watermark_color` | `#ffffff` | Hex colour for text watermarks |

![Watermark position selector showing position options](../images/admin-settings-display.png)

## Who gets watermarked

Watermarking is controlled by **Apply to**, not by a photo's privacy level:

- **All uploads** (`all`, the default) - every image any member uploads is watermarked.
- **Uploads from selected roles** (`roles`) - only images uploaded by the roles you tick under **Watermark uploads from** are watermarked. If you choose this and tick no roles, nothing is watermarked (the settings screen warns you when that happens).

## Watermark positions

| Value | Location |
|-------|----------|
| `top-left` | Top-left corner with padding |
| `top-right` | Top-right corner with padding |
| `center` | Centred on the image |
| `bottom-left` | Bottom-left corner with padding |
| `bottom-right` | Bottom-right corner with padding (default) |
| `tile` | Repeated across the whole image |

## Text watermarks

When **Watermark Type** is `text`, WPMediaVerse Pro renders the string in **Watermark Text**. Two tokens are supported:

- `{site}` - your site name.
- `{username}` - the uploader's public handle (their `user_nicename`, e.g. `@jane`). It never uses the WordPress login name.

The text size comes from **Text Size** and scales with the image so it stays proportional on any photo. When a TrueType font is available it is measured for accurate placement; otherwise the built-in GD bitmap font is used. Supply your own TTF with the `mvs_watermark_font_path` filter:

```php
add_filter( 'mvs_watermark_font_path', function ( $path, $config ) {
    return '/path/to/your-font.ttf';
}, 10, 2 );
```

## Logo watermarks

When **Watermark Type** is `image`, select an image from your WordPress Media Library using the **Watermark Image** field (its attachment ID is stored in `mvs_watermark_image_id`). WPMediaVerse Pro scales the logo to roughly 20% of the base image width before compositing. A PNG with a transparent background is recommended.

## For developers

The stamp is applied by Pro's `Watermarker` through the `mvs_watermark_stamp_file` filter, fired by the free plugin's upload pipeline on each new image before its thumbnails and WebP/AVIF variants are generated - so every derived size inherits the mark from a single draw.

To disable watermarking programmatically (for example on a staging site) without changing the stored setting, filter `mvs_watermark_enabled`:

```php
add_filter( 'mvs_watermark_enabled', '__return_false' );
```

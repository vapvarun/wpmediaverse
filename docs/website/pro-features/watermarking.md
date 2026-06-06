# Watermarking

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro composites text or logo watermarks onto image files using PHP's GD library. Watermarking generates a separate watermarked preview for gated media, so your stored originals are never modified.

![Watermark settings panel showing text and logo options](../images/admin-settings-display.png)

## Requirements

- PHP GD extension enabled (verify with `phpinfo()` - look for the `gd` section)
- For logo watermarks: a PNG file with a transparent background is recommended

## Enabling Watermarks

Go to **Media > Settings > Display > Watermark** and configure the options below.

| Option | Option Key | Default | Description |
|--------|-----------|---------|-------------|
| Enable Watermarking | `mvs_watermark_enabled` | `0` | Apply a watermark to downloaded image files |
| Watermark Type | `mvs_watermark_type` | `text` | `text` to overlay a text string, `logo` to overlay an image |
| Watermark Text | `mvs_watermark_text` | site title | The text to overlay (used when type is `text`); defaults to your site name |
| Watermark Image | `mvs_watermark_image_id` | `0` | WordPress attachment ID of the logo image |
| Position | `mvs_watermark_position` | `center` | Where on the image to place the watermark |
| Opacity | `mvs_watermark_opacity` | `40` | Watermark opacity, 0 (transparent) to 100 (opaque) |
| Font Size | `mvs_watermark_font_size` | `24` | Base font size for text watermarks (px) |
| Text Colour | `mvs_watermark_color` | `#ffffff` | Hex colour for text watermarks |

![Watermark position selector showing position options](../images/admin-settings-display.png)

## Watermark Positions

| Value | Location |
|-------|----------|
| `top-left` | Top-left corner with padding |
| `top-right` | Top-right corner with padding |
| `center` | Centred on the image (default) |
| `bottom-left` | Bottom-left corner with padding |
| `bottom-right` | Bottom-right corner with padding |
| `tile` | Repeated across the whole image (logo watermarks) |

## Text Watermarks

When **Watermark Type** is `text`, WPMediaVerse Pro renders the string in **Watermark Text**. When a TrueType font is available it is measured for accurate placement; otherwise the built-in GD bitmap font is used. Supply a TTF path with the `mvs_watermark_font_path` filter:

```php
add_filter( 'mvs_watermark_font_path', function( $path, $config ) {
    return '/path/to/your-font.ttf';
}, 10, 2 );
```

The text size is taken from the **Font Size** setting (`mvs_watermark_font_size`).

## Logo Watermarks

When **Watermark Type** is `logo`, select an image from your WordPress Media Library using the **Watermark Image** field (its attachment ID is stored in `mvs_watermark_image_id`). WPMediaVerse Pro scales the logo to roughly 20% of the base image width before compositing. A PNG with a transparent background is recommended.

## How Watermarks Are Applied

Watermarking produces a watermarked **preview image** for gated/protected media. The Pro `Watermarker` does the GD compositing through the `mvs_generate_watermark` filter; the original file in storage is never changed. You can adjust the whole watermark configuration (type, text, position, opacity, font size, colour) at runtime with the `mvs_watermark_config` filter:

```php
add_filter( 'mvs_watermark_config', function( $config ) {
    $config['opacity'] = 60;
    return $config;
} );
```

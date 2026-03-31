# Watermarking

WPMediaVerse Pro applies text or logo watermarks to image files using PHP's GD library. Watermarks are applied at download time so your stored originals are never modified.

![Watermark settings panel showing text and logo options](../images/admin-settings-display.png)

## Requirements

- PHP GD extension enabled (verify with `phpinfo()` — look for the `gd` section)
- For logo watermarks: a PNG file with a transparent background is recommended

## Enabling Watermarks

Go to **Media > Settings > Display > Watermark** and configure the options below.

| Option | Option Key | Default | Description |
|--------|-----------|---------|-------------|
| Enable Watermarking | `mvs_pro_watermark_enabled` | `0` | Apply a watermark to downloaded image files |
| Watermark Type | `mvs_pro_watermark_type` | `text` | `text` to overlay a text string, `logo` to overlay an image |
| Watermark Text | `mvs_pro_watermark_text` | (empty) | The text to overlay (used when type is `text`) |
| Watermark Image | `mvs_pro_watermark_image_id` | (empty) | WordPress attachment ID of the logo image |
| Position | `mvs_pro_watermark_position` | `bottom-right` | Where on the image to place the watermark |
| Opacity | `mvs_pro_watermark_opacity` | `70` | Watermark opacity, 0 (transparent) to 100 (opaque) |

![Watermark position selector showing position options](../images/admin-settings-display.png)

## Watermark Positions

| Value | Location |
|-------|----------|
| `top-left` | Top-left corner with padding |
| `top-right` | Top-right corner with padding |
| `center` | Centred on the image |
| `bottom-left` | Bottom-left corner with padding |
| `bottom-right` | Bottom-right corner with padding |

## Text Watermarks

When **Watermark Type** is `text`, WPMediaVerse Pro renders the string in **Watermark Text** using a bundled TrueType font. You can change the font by replacing `assets/fonts/watermark.ttf` in the Pro plugin directory, or by using the filter:

```php
add_filter( 'mvs_pro_watermark_font_path', function( $path ) {
    return '/path/to/your-font.ttf';
} );
```

Font size scales automatically based on the longest dimension of the output image.

## Logo Watermarks

When **Watermark Type** is `logo`, select an image from your WordPress Media Library using the **Watermark Image** field. WPMediaVerse Pro resizes the logo to 15% of the output image's width before compositing. Override that ratio:

```php
add_filter( 'mvs_pro_watermark_logo_ratio', function( $ratio ) {
    return 0.10; // 10% of image width.
} );
```

## How Watermarks Are Applied

Watermarks are applied dynamically when a user requests a download. The watermarked image is streamed directly to the browser — it is not cached to disk. The original file in storage is never changed.

If you want to apply watermarks to existing media in bulk, use WP-CLI:

```bash
wp mvs watermark apply --all
wp mvs watermark apply --media_id=123
```

## Bypassing Watermarks

Users with the `manage_options` capability and the media owner always download the original file without a watermark. To grant additional roles watermark-free downloads:

```php
add_filter( 'mvs_pro_skip_watermark', function( $skip, $media_id, $user_id ) {
    if ( user_can( $user_id, 'upload_files' ) ) {
        return true;
    }
    return $skip;
}, 10, 3 );
```

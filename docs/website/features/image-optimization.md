---
title: Image Optimization
since: "1.3.0"
tier: free
---

# Image Optimization

> **Included in Free** - This feature is available in the free version of MediaVerse.

MediaVerse can reduce image file sizes at upload time and creates WebP copies for faster loading. No extra plugin is required. Originals are never made larger: if re-encoding produces no gain, the original file is kept untouched.

## What It Does

When a JPEG, PNG, or GIF is uploaded:

1. If **Compress uploaded images** is on (it is off by default), the file is re-encoded at high quality.
2. The result is compared to the original. If it is smaller, the smaller file is committed. If not, the original is kept.
3. A WebP copy is generated alongside the original and every thumbnail size (on by default).
4. An AVIF copy can optionally be generated for even smaller file sizes (off by default; slower to encode).

Animated GIFs are detected and skipped from the lossless re-encode step. Their frames are preserved.

## How Browsers Receive Images

MediaVerse uses a `<picture>` element with progressive fallback. Browsers receive the most efficient format they support:

1. AVIF (if enabled and available)
2. WebP (if enabled and available)
3. Original JPEG/PNG/GIF (always served as a fallback)

This format negotiation applies across the explore grid, BuddyPress activity stream, the media dashboard, single media pages, and the lightbox. No JavaScript is required; it is handled by native browser behavior.

## Settings

Access the setting at **MediaVerse > Settings > Storage**.

| Setting | Default | Description |
|---------|---------|-------------|
| Compress uploaded images | Off | Makes new images 10-30% smaller. JPEGs lose a little quality. |

Removing the GPS location from photos is a separate setting, **Remove location from photos**, on **MediaVerse > Settings > General**.

WebP copies are created automatically whenever your server can write WebP (on by default), and AVIF copies are off by default. Neither has a screen in the settings; developers can change them with the `mvs_generate_webp` and `mvs_generate_avif` options. AVIF is around 30 to 50 percent smaller than WebP, but encoding is much slower and needs Imagick with libheif, or GD on PHP 8.1+ with libavif.

Changing a setting takes effect on the next upload. Settings do not retroactively process existing media.

## Admin: Optimization Details

On **MediaVerse > All Media**, click **Details** under an image to open its details page. The **Optimization** section shows a status badge:

| Badge | Meaning |
|-------|---------|
| Optimized | The original was re-encoded and made smaller. Original size, size after optimization and space saved are shown. |
| WebP copy created | No gain on the original, but a WebP copy was generated. |
| No size gain | Re-encoding did not make the file smaller. |
| Not optimized | The image has not been through the optimization pipeline yet. |
| Could not optimize | Optimization failed. Try again with **Re-optimize**. |

The **Actions** section has a **Re-optimize** button that runs the optimization again for that image. The page also lists the WebP copies of each size.

## Bulk Optimization and WP-CLI

To optimize images uploaded before 1.3.0, use WP-CLI:

```bash
wp mvs optimize <id>
wp mvs optimize-bulk
```

Both commands are resume-safe. See the [WP-CLI reference](../developer-guide/wp-cli.md) for all available options.

## For Developers

To replace the built-in optimizer with an external service (EWWW, Imagify, Smush, ShortPixel, or a custom compressor), hook into the `mvs_optimize_image` filter. See the [Hooks and Filters reference](../developer-guide/hooks-filters.md) for the full signature and examples.

---
title: Image Optimization
since: "1.3.0"
tier: free
---

# Image Optimization

> **Included in Free** - This feature is available in the free version of WPMediaVerse.

WPMediaVerse automatically reduces image file sizes at upload time. No extra plugin is required. Originals are never made larger: if re-encoding produces no gain, the original file is kept untouched.

## What It Does

When a JPEG, PNG, or GIF is uploaded:

1. The file is re-encoded at high quality with embedded camera metadata stripped.
2. The result is compared to the original. If it is smaller, the smaller file is committed. If not, the original is kept.
3. A WebP copy is generated alongside the original and every thumbnail size (on by default).
4. An AVIF copy can optionally be generated for even smaller file sizes (off by default; slower to encode).

Animated GIFs are detected and skipped from the lossless re-encode step. Their frames are preserved.

## How Browsers Receive Images

WPMediaVerse uses a `<picture>` element with progressive fallback. Browsers receive the most efficient format they support:

1. AVIF (if enabled and available)
2. WebP (if enabled and available)
3. Original JPEG/PNG/GIF (always served as a fallback)

This format negotiation applies across the explore grid, BuddyPress activity stream, the media dashboard, single media pages, and the lightbox. No JavaScript is required; it is handled by native browser behavior.

## Settings

Access these settings at **Media > Settings > Storage**.

| Setting | Default | Description |
|---------|---------|-------------|
| Compress uploaded images | On | Re-encodes JPEG, PNG, and GIF originals to remove camera metadata and reduce file size. Works alongside EWWW, Imagify, Smush, and ShortPixel. |
| Create WebP copies for faster loading | On | Saves a WebP copy next to every original and thumbnail. WebP files are typically 25 to 35 percent smaller than JPEG. |
| Create AVIF copies for the smallest possible files | Off | Saves an AVIF copy next to every original and thumbnail. AVIF is around 30 to 50 percent smaller than WebP. Encoding is much slower than WebP. Requires Imagick with libheif, or GD on PHP 8.1+ with libavif. |

Toggling any setting takes effect on the next upload. Settings do not retroactively process existing media.

## Admin: Optimization Column

The **Optimization** column on the **Media > All Media** list shows the result for each image:

| Badge | Meaning |
|-------|---------|
| `-23%` (green) | The original was re-encoded and reduced by that percentage. |
| `WebP ready` (green) | No lossless gain on the original, but a WebP copy was generated. |
| `No lossless gain` (grey) | Re-encoding did not reduce the file and WebP was not generated. |
| `Not optimized` | The image has not been through the optimization pipeline yet. |

Each image row also has two row actions: **Optimize** (re-runs the optimization pipeline for that image) and **Details** (opens a detail page at `?page=mvs-media&view=details&media_id=N` showing file sizes, savings percentage, and variant URLs).

## Bulk Optimization and WP-CLI

To optimize images uploaded before 1.3.0, use WP-CLI:

```bash
wp mvs optimize <id>
wp mvs optimize-bulk
```

Both commands are resume-safe. See the [WP-CLI reference](../developer-guide/wp-cli.md) for all available options.

## For Developers

To replace the built-in optimizer with an external service (EWWW, Imagify, Smush, ShortPixel, or a custom compressor), hook into the `mvs_optimize_image` filter. See the [Hooks and Filters reference](../developer-guide/hooks-filters.md) for the full signature and examples.

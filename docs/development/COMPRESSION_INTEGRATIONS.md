# Integrating image-compression plugins with WPMediaVerse

**Since:** 1.2.2

WPMediaVerse stores media outside the WordPress attachment system, so image-compression plugins (EWWW, Imagify, Smush, ShortPixel, etc.) don't see uploads automatically. The `mvs_optimize_image` filter is the single extension point that lets them.

This page ships four ready-to-paste mu-plugin snippets (one per major compressor). Drop one in `wp-content/mu-plugins/` and you're done.

## The contract

```php
/**
 * @param string $file_path Absolute path to the file on local disk.
 * @param array  $context   { media_id: int, variant: string, mime: string, user_id: int }
 *                          variant is the size key ('original', 'large', 'medium',
 *                          'thumb', …), optionally suffixed '-webp' or '-avif' for
 *                          the sibling we generate from it.
 * @return string|WP_Error  Same path, replacement path, or WP_Error to abort the pass.
 */
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    // ... your compressor here ...
    return $file_path;
}, 10, 2 );
```

The filter fires:
- Once on the original temp file BEFORE `$driver->store()`. Cloud-storage installs benefit from this - the optimized bytes go straight to S3/Bunny.
- Once per thumbnail variant after `multi_resize()` writes it.
- Once per WebP sibling and once per AVIF sibling we generate (so you can optionally re-process those too).

WPMediaVerse's own lossless pass and WebP/AVIF generation run AFTER your filter. If you've already optimized the file in place, our pass is a no-op on the already-optimized bytes.

## EWWW Image Optimizer

```php
<?php
/**
 * Plugin Name: WPMediaVerse + EWWW bridge
 */
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    if ( ! function_exists( 'ewww_image_optimizer' ) ) {
        return $file_path;
    }
    // EWWW operates in place on the absolute path. The 4th arg `true` means
    // "don't fail if the source can't be backed up" - we already control the
    // file lifecycle.
    ewww_image_optimizer( $file_path, 4, false, true );
    return $file_path;
}, 10, 2 );
```

EWWW respects its own admin settings (lossy / lossless / WebP toggle). No further configuration needed.

## Imagify

```php
<?php
/**
 * Plugin Name: WPMediaVerse + Imagify bridge
 */
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    if ( ! class_exists( '\Imagify\Optimization\File' ) ) {
        return $file_path;
    }
    try {
        $file   = new \Imagify\Optimization\File( $file_path );
        $result = $file->optimize();
        if ( is_wp_error( $result ) ) {
            return $result; // logged as warning by WPMediaVerse; original kept.
        }
    } catch ( \Throwable $e ) {
        // Imagify's older versions throw on credit exhaustion etc.
        return new WP_Error( 'imagify_failed', $e->getMessage() );
    }
    return $file_path;
}, 10, 2 );
```

Imagify hits its API on every call, so it adds 200–800ms per image. Consider running our built-in lossless pass alongside (it's free and instant) by leaving `Optimize originals` enabled.

## Smush

```php
<?php
/**
 * Plugin Name: WPMediaVerse + Smush bridge
 */
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    if ( ! class_exists( '\Smush\Core\Modules\Smush' ) ) {
        return $file_path;
    }
    $smush = \Smush\Core\Modules\Smush::get_instance();
    // do_smushit() returns a status array or WP_Error.
    $result = $smush->do_smushit( $file_path );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return $file_path;
}, 10, 2 );
```

Smush operates in place. Free version supports JPEG/PNG/GIF; Pro adds WebP - but we generate WebP siblings ourselves, so the free tier is enough alongside WPMediaVerse.

## ShortPixel

```php
<?php
/**
 * Plugin Name: WPMediaVerse + ShortPixel bridge
 */
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    if ( ! function_exists( 'wp_shortpixel_optimize_image' ) ) {
        // ShortPixel's path-based API. Older versions exposed a class
        // ShortPixelAPI; check both before giving up.
        if ( ! class_exists( '\ShortPixel\API' ) ) {
            return $file_path;
        }
        $api      = new \ShortPixel\API();
        $response = $api->doRequests( array( $file_path ) );
        return is_array( $response ) ? $file_path : new WP_Error( 'shortpixel_failed', 'API returned non-array' );
    }
    wp_shortpixel_optimize_image( $file_path );
    return $file_path;
}, 10, 2 );
```

ShortPixel processes via their API too; account credits apply. Variants (`$context['variant']` !== 'original') are typically not worth API spend - gate the call if you want to skip them:

```php
if ( 'original' !== ( $context['variant'] ?? '' ) ) {
    return $file_path;
}
```

## Common patterns

### Skip the WebP / AVIF siblings (your compressor handles them itself)

```php
$variant = (string) ( $context['variant'] ?? '' );
if ( str_ends_with( $variant, '-webp' ) || str_ends_with( $variant, '-avif' ) ) {
    return $file_path;
}
```

### Only process originals (let WPMediaVerse handle variants)

```php
if ( 'original' !== ( $context['variant'] ?? '' ) ) {
    return $file_path;
}
```

### Async via Action Scheduler

```php
add_filter( 'mvs_optimize_image', function( $file_path, $context ) {
    as_enqueue_async_action( 'my_compressor_run', array( $file_path, $context ), 'wpmediaverse' );
    return $file_path; // upload completes immediately; compression happens in background.
}, 10, 2 );

add_action( 'my_compressor_run', function( $file_path, $context ) {
    // ... call your compressor here ...
} );
```

Note: async means the WebP sibling WPMediaVerse generates is produced from the un-optimized source. If you want your compressor to see the file FIRST, run synchronously.

## Bulk processing existing media

For any compressor above, run `wp mvs optimize-bulk` after installing the bridge. The filter fires for every existing image, so your compressor processes the back catalog the same way it processes new uploads:

```bash
wp mvs optimize-bulk --include-variants
```

Resume support is built in - the bulk command writes `_mvs_optimized_at` per row and skips already-processed rows on subsequent runs.

## Disabling the built-in pass

If you only want your compressor to run (no WPMediaVerse lossless pass, no WebP/AVIF siblings), turn off the optimization toggles on the admin Storage tab - they are the `mvs_optimize_originals`, `mvs_generate_webp` and `mvs_generate_avif` options. The filter still fires, so your compressor still runs.

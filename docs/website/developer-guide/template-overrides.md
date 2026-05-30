# Template Overrides

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


WPMediaVerse uses a template loading system that checks your active theme before falling back to plugin templates. This lets you fully customize media page layouts without modifying the plugin.

## How It Works

The `TemplateLoader` class calls `locate_template()` to check these locations in order:

1. **Child theme:** `wp-content/themes/child-theme/wpmediaverse/template-name.php`
2. **Parent theme:** `wp-content/themes/parent-theme/wpmediaverse/template-name.php`
3. **Plugin default:** `wp-content/plugins/wpmediaverse/templates/template-name.php`

## Creating a Theme Override

Create a `wpmediaverse/` directory inside your theme and copy the template file you want to modify:

```
wp-content/themes/your-theme/
└── wpmediaverse/
    ├── media-single.php       # Single media item page
    ├── album.php              # Single album page
    ├── collection.php         # Single collection page
    ├── explore.php            # Media archive / explore page
    └── profile-edit.php       # Profile edit page
```

## Available Templates

| File | Used For |
|------|---------|
| `media-single.php` | Single `mvs_media` post page |
| `album.php` | Single `mvs_album` post page |
| `collection.php` | Single `mvs_collection` post page |
| `explore.php` | `mvs_media` and `mvs_album` archive, taxonomy archives, and `/media/@username/` profile pages |
| `profile-edit.php` | `/media/edit-profile/` endpoint |

## Available Partials

Template partials are located in `templates/partials/` and loaded with `TemplateLoader::get_template()`:

```php
WPMediaVerse\Core\TemplateLoader::get_template( 'partials/media-card.php', array(
    'media_id' => $post->ID,
) );
```

## Using TemplateLoader in Custom Code

```php
use WPMediaVerse\Core\TemplateLoader;

// Load a template with data.
TemplateLoader::get_template( 'media-single.php', array(
    'media_id' => 123,
    'show_reactions' => true,
) );

// Just locate the path (without loading).
$path = TemplateLoader::locate( 'explore.php' );
```

## Getting Media URLs in a Template (MediaUrl)

When you write a custom template you almost always need a media URL — a thumbnail for a grid card, or the full file for a lightbox. **Do not hand-build these URLs** from `wp_upload_dir()` and **do not call `SignedUrlService` directly.** A raw upload path breaks the moment a site enables cloud storage or marks media private, and calling the signing service directly means re-implementing the privacy gate.

Since 1.5.0 the read-side facade `WPMediaVerse\Core\MediaUrl` is the single entry point. It resolves the active storage driver, runs the privacy check, and returns either a signed `/serve` URL or a direct CDN URL — whichever is correct for that media's privacy and the current driver.

```php
use WPMediaVerse\Core\MediaUrl;

// Thumbnail URL for the current viewer (size: large | medium | thumb).
$thumb = MediaUrl::thumb( $media_id, 'large' );

// Full original file URL for the current viewer.
$full = MediaUrl::file( $media_id );

if ( $thumb ) {
    printf(
        '<img src="%s" alt="%s" loading="lazy" />',
        esc_url( $thumb ),
        esc_attr( get_the_title( $media_id ) )
    );
}
```

Both methods return an **empty string** when the service isn't ready (very early bootstrap) or when the viewer's identity is rejected by the privacy gate — always guard the return value before printing, as shown above.

### Public static methods

```php
namespace WPMediaVerse\Core;

final class MediaUrl {

    // Signed /serve URL for a thumb variant. $size: large|medium|thumb.
    // $ttl 0 = service default. $user_id null = current user; 0 = broadcast surface.
    // $skip_privacy is forced to false when the resolved user id is 0.
    public static function thumb(
        int $media_id,
        string $size = 'large',
        int $ttl = 0,
        ?int $user_id = null,
        bool $skip_privacy = true
    ): string;

    // Signed /serve URL for the full original file.
    public static function file( int $media_id, ?int $user_id = null ): string;

    // The meta key holding a variant's stored URL.
    // e.g. ('large') -> 'thumb_large'; ('large','webp') -> 'thumb_large_webp'.
    public static function variant_meta_key(
        string $size,
        string $format = VariantSpec::FORMAT_PRIMARY
    ): string;

    // The meta key holding a variant's driver-agnostic relative path
    // (the URL key with a `_path` suffix). e.g. ('large') -> 'thumb_large_path'.
    public static function variant_path_meta_key(
        string $size,
        string $format = VariantSpec::FORMAT_PRIMARY
    ): string;
}
```

The two `*_meta_key()` helpers are the single source of truth for the size+format → meta-key mapping. Use them instead of hardcoding `'thumb_large_webp'`-style strings when you need to read variant meta yourself:

```php
use WPMediaVerse\Core\MediaUrl;
use WPMediaVerse\Services\VariantSpec;

$webp_key = MediaUrl::variant_meta_key( 'large', VariantSpec::FORMAT_WEBP ); // 'thumb_large_webp'
$webp_url = get_post_meta( $media_id, $webp_key, true );
```

> **Note:** `TemplateHelpers::get_thumb_url()` is now a one-line delegate to `MediaUrl::thumb()`, so existing templates that already use it keep working unchanged — `MediaUrl` is simply the canonical name to reach for in new code.

## Filtering the Template Path

You can override any template path using the `mvs_locate_template` filter:

```php
add_filter( 'mvs_locate_template', function( string $template, string $name, string $path ) {
    // Use a completely different directory for all WPMediaVerse templates.
    $override = WP_CONTENT_DIR . '/my-media-templates/' . $name;
    return file_exists( $override ) ? $override : $template;
}, 10, 3 );
```

## BuddyX Theme Integration

WPMediaVerse adds the `mvs-page` and `no-sidebar` CSS body classes to all WPMediaVerse pages. The BuddyX theme (and any theme that handles these classes) renders these pages full-width without a sidebar.

Pages that receive these classes:

- Single `mvs_media`, `mvs_album`, `mvs_collection` posts
- `mvs_media` and `mvs_album` archives
- `mvs_tag` and `mvs_category` taxonomy pages
- `/media/edit-profile/` endpoint
- `/media/@username/` profile pages
- Any page whose ID matches an `mvs_page_*` option (e.g., the page containing `[mvs_dashboard]`)

## Shortcode Context in Block Templates

When a shortcode renders a block template (via `Shortcodes::render_block_template()`), the variable `$mvs_shortcode_context` is set to `true`. Block `render.php` files should check this variable before calling `get_block_wrapper_attributes()`, which causes a PHP warning outside a block context:

```php
if ( empty( $mvs_shortcode_context ) ) {
    $wrapper_attrs = get_block_wrapper_attributes();
}
```

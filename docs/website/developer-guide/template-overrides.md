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

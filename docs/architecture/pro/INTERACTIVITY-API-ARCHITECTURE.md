# WPMediaVerse — Interactivity API Architecture

## How Script Modules Work in This Plugin

WordPress Interactivity API stores are loaded as **ES modules** (`type="module"`) via `wp_enqueue_script_module()`. The key distinction:

| Build Format | Source Path | Loaded Via | Uses |
|---|---|---|---|
| **ESM (raw source)** | `src/blocks/*/view.js` | `wp_enqueue_script_module()` | `import { store } from '@wordpress/interactivity'` |
| **IIFE (webpack build)** | `build/blocks/*/view.js` | `wp_enqueue_script()` | `window.wp.interactivity` global |

**Rule:** Always load Interactivity API stores from `src/` (ESM), never from `build/` (IIFE). The `build/` output is for block editor scripts, not frontend viewScriptModules.

## Registered Stores

### Free Plugin (`wpmediaverse`)

| Store Namespace | Source File | Purpose | Loaded By |
|---|---|---|---|
| `mvs/shared-ui` | `src/blocks/shared-ui/view.js` | Toast, confirm, upload modal, **lightbox**, tag autocomplete | `Plugin.php → enqueue_frontend_assets()` on all MVS pages |
| `mvs/explore-feed` | `src/blocks/explore-feed/view.js` | Load More, filter, search on explore grid | Block render (`build/blocks/explore-feed/render.php`) |
| `mvs/explore` | `src/blocks/explore-view/view.js` | Tag cloud Interactivity for explore page | Pro template `explore-filters.php` |
| `mvs/media-grid` | `src/blocks/media-grid/view.js` | Grid interactions | Block render |
| `mvs/media-player` | `src/blocks/media-player/view.js` | Video/audio player controls | Block render |
| `mvs/media-upload` | `src/blocks/media-upload/view.js` | Upload block interactions | Block render |
| `mvs/media-stats` | `src/blocks/media-stats/view.js` | Stats display | Block render |
| `mvs/story-viewer` | `src/blocks/story-viewer/view.js` | Stories carousel | Block render |
| `mvs/album-viewer` | `src/blocks/album-viewer/view.js` | Album gallery view | Block render |
| `mvs/lock-overlay` | `src/blocks/lock-overlay/view.js` | Pro feature lock overlay | Block render |

### How `shared-ui` Gets Loaded

The shared-ui store is the most critical — it provides the lightbox, toasts, and upload modal used across ALL frontend pages.

```
Plugin.php::enqueue_frontend_assets()
  ↓ condition: is_page(explore/dashboard/upload) OR mvs_media/mvs_album/mvs_collection OR mvs_tag/mvs_category
  ↓
wp_enqueue_script_module(
    '@mvs/shared-ui',
    MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',   ← MUST be src/, not build/
    array( '@wordpress/interactivity' ),
    MVS_VERSION
);
```

### How Other Stores Get Loaded

Stores loaded by block renders auto-register via `viewScriptModule` in `block.json`. Stores loaded by pro templates use explicit `wp_enqueue_script_module()` in the template file.

**Pro template pattern** (e.g., `explore-filters.php`):
```php
wp_enqueue_script_module(
    '@mvs/explore-view',
    MVS_PLUGIN_URL . 'src/blocks/explore-view/view.js',
    array( '@wordpress/interactivity' ),
    MVS_VERSION
);
```

## Lightbox Click Flow

```
User clicks card on explore/dashboard/profile
  ↓
load-more.js delegated handler (vanilla JS, not Interactivity)
  ↓ e.target.closest('[data-media-id]')
  ↓ e.preventDefault() ← blocks default <a> navigation
  ↓
window.mvsOpenLightbox(mediaId)
  ↓ bridge function set by shared-ui store
  ↓
store('mvs/shared-ui').actions.openLightboxById(mediaId)
  ↓ fetches REST /mvs/v1/media/{id}
  ↓ fetches reactions, comments, stats, favorite in parallel
  ↓ sets state.lightboxVisible = true
  ↓
Lightbox overlay renders via data-wp-bind directives
```

**Critical dependency:** If `shared-ui` doesn't load, `window.mvsOpenLightbox` is undefined, cards neither navigate nor open lightbox.

## Vanilla JS Bridge Scripts

| Script | File | Purpose | Depends On |
|---|---|---|---|
| `mvs-card-builders` | `assets/js/frontend/card-builders.js` | Builds card DOM for Load More pagination (grid/pinterest/flickr/dribbble/instagram) | None |
| `mvs-load-more` | `assets/js/frontend/load-more.js` | Load More button + delegated lightbox click handler | `mvs-card-builders` |

These are regular scripts (not modules) loaded via `wp_enqueue_script()` with `strategy: defer`.

## Layout Templates (Pro)

The pro plugin overrides the default explore/profile pages with layout-specific templates:

| Layout | Feed Template | Profile Template | Card Template |
|---|---|---|---|
| Instagram | `layouts/instagram/feed.php` | `layouts/instagram/profile.php` | `layouts/instagram/partials/feed-card.php` |
| Flickr | `layouts/flickr/feed.php` | `layouts/flickr/profile.php` | (inline) |
| Pinterest | `layouts/pinterest/feed.php` | `layouts/pinterest/profile.php` | (inline) |
| Dribbble | `layouts/dribbble/feed.php` | `layouts/dribbble/profile.php` | (inline) |

Layout is set via `mvs_pro_feed_layout` option. The `LayoutManager.php` in the pro plugin routes to the correct template.

## Common Pitfalls

1. **Never load from `build/` for Interactivity stores** — IIFE format uses `window.wp.interactivity` which doesn't work as a script module
2. **`wp_enqueue_script_module` deps format** — must be `array('@wordpress/interactivity')` not `array('wp-interactivity')`
3. **Streak badge in display names** — `mvs_user_display_name` filter appends `<span>` HTML. Use `wp_kses()` not `esc_html()` when rendering filtered names
4. **`data-wp-interactive` on cards** — the namespace attribute alone doesn't auto-load the module; it must be explicitly enqueued

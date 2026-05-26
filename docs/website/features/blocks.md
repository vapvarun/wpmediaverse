# Gutenberg Blocks

> **Included in Free** - This feature is available in the free version of WPMediaVerse.


WPMediaVerse registers **9** Gutenberg blocks under the **WPMediaVerse** block category. All blocks use the WordPress Interactivity API for reactive front-end behavior without a separate JavaScript framework.

![Gutenberg block inserter showing the WPMediaVerse block category](../images/admin-overview.png)

## Block List

| Block Name | Handle | Description |
|------------|--------|-------------|
| Media Upload | `mvs/media-upload` | Frontend file upload form with drag-and-drop |
| Media Grid | `mvs/media-grid` | Filterable, paginated media gallery grid |
| Media Player | `mvs/media-player` | Single-item audio/video player with controls |
| Album Viewer | `mvs/album-viewer` | Displays a single album's media in a grid |
| Member Photos | `mvs/member-photos` | Auto-detects the user (explicit `userId` → BuddyPress displayed user → post author → current user) and renders their media grid |
| PDF Viewer | `mvs/pdf-viewer` | Browser-native PDF embed using the `#view=FitH` URL fragment. Configurable height, optional toolbar, five distinct empty states |
| Media Stats | `mvs/media-stats` | Site-wide or per-user media statistics |
| Explore Feed | `mvs/explore-feed` | Infinite-scroll explore feed (all public media) with search autocomplete |
| Lock Overlay | `mvs/lock-overlay` | Paywall/restriction overlay for any block |

*The Story Viewer block source ships in 1.2.0 but isn't registered yet - full Story create-flow + REST endpoint arrives in 1.2.1.*

## Interactivity API Architecture

Each block ships with a `view.js` module registered via `wp_enqueue_script_module()`. Blocks communicate through shared state in the `mvs` interactivity store. The `shared-ui` module provides common utilities (REST fetching, nonce management) shared by all blocks.

## Using Blocks in Templates

All registered blocks can be used in Full Site Editing (FSE) templates, template parts, and block patterns. They are fully compatible with query blocks and block themes.

## Media Upload Block

![Media Upload block in the editor](../images/upload-page.png)

**Block Settings:**
- Max Files per Upload (default: 10)
- Show Privacy Selector (default: on)

**Upload modal polish (1.2.0):**
- Preview tiles show the filename (truncated tastefully) and a per-tile remove (×) button.
- Audio files render with a dedicated audio fallback icon instead of a broken thumbnail.
- A row of eight popular tag pills appears below the tags input - click to append, no duplicates.

## Media Grid Block

![Media Grid block showing filter controls and grid layout](../images/explore-feed.png)

**Block Settings:**
- Media Type Filter (image/video/audio/all)
- Category Filter
- Tag Filter
- Sort Order - `date`, `title`, `popular`, `views`, `reactions`, `random`
- Sort Direction - `asc` / `desc`
- User ID filter (`userId`) - restrict the grid to a single author
- Lightbox (default: on)
- Show Reactions (default: on)

Grid columns and pagination inherit from **Media > Settings > Display**.

**Sorting (1.2.0):** Most Popular and Most Reactions sorts join the `mvs_media_stats` table to rank by aggregate engagement. Random sort reshuffles each page load.

## Media Player Block

**Block Settings:**
- Media ID (required - use the picker to select)
- Autoplay (default: off)
- Loop (default: off)
- Show Download Button (default: off)

## Album Viewer Block

**Block Settings:**
- Album (select from a dropdown of your albums)
- Columns override
- Show Title / Show Description

## Story Viewer Block (Coming Soon)

Currently displays a horizontal bar of recent uploaders with circular avatars (visible in Instagram layout mode). A full story viewer with tap-to-advance navigation, create flow, and dedicated REST endpoint arrives in 1.2.1.

## Member Photos Block

The Member Photos block renders a single member's media grid. The block resolves the user automatically using a four-step fallback chain so the same block works in profile templates, member-specific pages, author archives, and BuddyPress profile tabs without per-page wiring.

**Resolution order:**
1. Explicit `userId` attribute on the block
2. BuddyPress displayed user (when on a BP profile)
3. Post author (when in a single-post context)
4. Current logged-in user

**Block Settings:**
- User ID (optional - leave empty to auto-detect)
- Items Per Page
- Sort Order - same options as Media Grid

## PDF Viewer Block

The PDF Viewer block embeds a PDF using the browser's native viewer via the `#view=FitH` URL fragment - no third-party JS, no licensing concerns.

**Block Settings:**
- Media ID (required - pick a PDF media item)
- Height (200–1400 px, default 600)
- Show Toolbar (default: on)

**Empty states:** the block emits five distinct empty states - no media selected, wrong media type, missing file, access denied, and a generic fallback.

## Media Stats Block

**Block Settings:**
- Show Views (default: on)
- Show Downloads (default: on)
- Show Reactions (default: on)
- Show Top Media (default: on)
- Top Media Count (default: 5)

## Explore Feed Block

The Explore Feed block provides an infinite-scroll feed of all public media. It supports URL-based filtering via `?mvs_tag=slug` and `?s=search-term` query parameters.

**Search autocomplete (1.2.0):** the search input now shows a type-ahead dropdown - top eight title matches, debounced 250 ms, full keyboard navigation (Arrow keys, Enter, Escape) and ARIA combobox semantics for screen reader users.

## Lock Overlay Block

The Lock Overlay block wraps any other block content and shows a restriction message to users who do not meet access criteria. Configure access rules via the **Access Control** REST API.

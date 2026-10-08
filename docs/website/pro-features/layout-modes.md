# Layout Modes

> **Requires MediaVerse Pro** - This feature is available exclusively in the Pro version.

Transform your media community's look with one click - choose the visual style that fits your audience, from Instagram-style grids to Pinterest masonry boards.

## What You Can Do

- Switch between four distinct visual layouts without touching code
- Match your community's purpose: photo sharing, discovery, portfolios, or design showcases
- Drop a specific layout on any page with its dedicated block or shortcode (Instagram, Flickr, Pinterest, Dribbble feed)
- Conditionally switch the active layout by page context using the `mvs_active_layout` filter

## The Four Layouts at a Glance

| Mode | Best For |
|------|----------|
| Instagram | Photo-sharing communities, daily uploads, stories |
| Pinterest | Inspiration boards, discovery-focused sites |
| Flickr | Photography portfolios, camera clubs |
| Dribbble | Design showcases, creative portfolios |

## How to Switch Layouts (for Site Owners)

1. Go to **MediaVerse > Settings > Display**
2. Find the **Layout** option and select your preferred mode: **Instagram Feed**, **Pinterest Masonry**, **Flickr Justified** or **Dribbble Shots**. The free layouts, such as the square grid, are in the same list.
3. Click **Save Changes** - the explore page and all profile media tabs update immediately

Choosing a free layout puts the feed back on the plain grid.

## What Changes for Users When You Switch Layouts

- The explore/browse page re-renders in the new layout style
- All user profile media tabs switch to the new layout automatically
- The lightbox is the same in every layout: same actions, same comments
- Stories appear above the grid in Instagram mode only
- Search and tag filtering behave identically to the default layout, including the empty states (2.2.0): a zero-result search shows "No results for ..." with a Browse-all button and popular-tag chips, and an unknown tag shows "Tag not found" instead of an unfiltered feed

![Display settings tab with layout, items per page, downloads and stories](../images/settings-display.webp)

---

## Choosing a Layout Mode

The selected mode applies to the explore archive and the user profile media tab. For a specific layout on a specific page, use that layout's own block (e.g. **Instagram Feed**) or shortcode (e.g. `[mvs_pro_instagram_feed]`) instead of relying on the global setting.

---

## Instagram Mode

Perfect for photo-sharing communities and daily uploads.

![Instagram layout: a single-column feed of large cards](../images/layout-instagram.webp)

The Instagram layout renders a vertical card feed. Each post shows the author avatar, full-width photo, reaction/comment/share buttons, like count, caption, and inline comment box - identical to the Instagram experience.

- When [Stories](stories.md) is switched on, the stories bar appears above the feed
- Each card has heart, comment, share, and favorite buttons (and a Boost button when boosts are on)
- Clicking "Expand" opens the lightbox with the full media detail view
- Other users' posts show a "Following" button in the card header

**Feed template:** `templates/layouts/instagram/feed.php`
**Profile template:** `templates/layouts/instagram/profile.php`

---

## Pinterest Mode

Ideal for inspiration boards, discovery-focused sites, and mixed-format content.

![Pinterest layout: a masonry grid with cards of different heights](../images/layout-pinterest.webp)

The Pinterest layout uses a masonry algorithm that preserves each image's original proportions. Cards include the media title and a truncated description below the image.

- Columns follow the screen width: 4 on desktop, 3 on tablets, 2 on small tablets, 1 on phones (the **Grid Columns** setting applies to the square grid only)
- A **Load More** button at the end of the feed loads the next page

**Feed template:** `templates/layouts/pinterest/feed.php`
**Profile template:** `templates/layouts/pinterest/profile.php`

---

## Flickr Mode

Best for photography portfolios, camera clubs, and image-quality-focused communities.

![Flickr layout: justified rows that fill the full width](../images/layout-flickr.webp)

The Flickr layout uses a justified gallery algorithm: images in each row are resized to fill the full container width while maintaining a consistent row height.

- Clicking an image opens the lightbox
- A member's profile shows the same justified gallery, limited to their media

**Feed template:** `templates/layouts/flickr/feed.php`
**Profile template:** `templates/layouts/flickr/profile.php`

Drop the Flickr layout on a specific page with the **Flickr Feed** block or the `[mvs_pro_flickr_feed]` shortcode.

---

## Dribbble Mode

Great for design showcases, creative portfolios, and high-resolution work.

![Dribbble layout: a grid of large, evenly sized shots](../images/layout-dribbble.webp)

The Dribbble layout presents media as large portfolio shots in a responsive grid (cards at least 300px wide, one column on phones). Each card shows the title, view count, and reaction count on hover. This layout is optimised for high-resolution PNG and GIF files.

- A member's profile shows the same grid, limited to their media

**Feed template:** `templates/layouts/dribbble/feed.php`
**Profile template:** `templates/layouts/dribbble/profile.php`

---

## Putting a Specific Layout on a Specific Page

Each layout ships as its own block and shortcode, so you can mix layouts across pages without changing the global setting:

| Layout | Block | Shortcode |
|--------|-------|-----------|
| Instagram | Instagram Feed | `[mvs_pro_instagram_feed]` |
| Flickr | Flickr Feed | `[mvs_pro_flickr_feed]` |
| Pinterest | Pinterest Feed | `[mvs_pro_pinterest_feed]` |
| Dribbble | Dribbble Feed | `[mvs_pro_dribbble_feed]` |

## Overriding the Active Layout in Code

The active layout passes through the `mvs_active_layout` filter, so you can switch it by context:

```php
add_filter( 'mvs_active_layout', function( $layout ) {
    // Force the Flickr layout on archive pages.
    if ( is_post_type_archive( 'mvs_media' ) ) {
        return 'flickr';
    }
    return $layout;
} );
```

# What's New in 1.2.0

WPMediaVerse 1.2.0 is a UX completeness release focused on filling gaps surfaced by real-world use. Twelve customer-visible improvements ship together, plus a full WCAG 2.1 AA accessibility pass.

> **Included in Free** - Every item on this page applies to the free version. Pro picks up these improvements automatically because Pro builds on top of free.

## Member Photos block + shortcode

A new `mvs/member-photos` Gutenberg block and matching `[mvs_member_photos]` shortcode render any member's media grid. The block auto-detects the right user - explicit `userId` attribute first, then the BuddyPress displayed user, then the post author, then the current user - so the same block can be dropped into a profile template, a member-specific page, or an author archive without any wiring.

## PDF Viewer block + shortcode

A new `mvs/pdf-viewer` block and `[mvs_pdf_viewer]` shortcode embed PDFs using the browser-native viewer (the `#view=FitH` URL fragment). Configurable height, optional toolbar, five distinct empty states (no media, wrong type, missing file, access denied, generic). No third-party JS, no plugins to install, no licensing fuss.

## Sort options on Media Grid

The Media Grid block and `[mvs_gallery]` shortcode now support four new sort orders - **Most Popular**, **Most Viewed**, **Most Reactions**, and **Random** - alongside the existing Date and Title sorts. A new direction toggle (ascending / descending) and a per-author filter (`userId`) make the grid usable as a "Top media by this member" surface.

## Search autocomplete on Explore

The Explore page (and `[mvs_explore_feed]` shortcode) now shows a type-ahead dropdown as you type in the search box - top eight title matches, debounced, with full keyboard navigation (Arrow keys, Enter, Escape) and ARIA combobox semantics for screen reader users.

## Lightbox: Download, Fullscreen, and `F` shortcut

The lightbox toolbar gains two new buttons - **Download** (writes to media stats; gated by the new global Allow Downloads toggle) and **Fullscreen** (also bound to the `F` keyboard shortcut). The Share button's third-popup `window.prompt()` fallback is gone; it's now `navigator.share` → clipboard copy → toast on failure.

## Per-media Edit modal

The settings cog on dashboard cards opens a prefilled edit modal - change title, description, privacy level, and per-media Allow Downloads in place. No more clicking through to a separate edit screen for a quick rename.

## Open Graph + Twitter Card meta

`/media/{slug}/` URLs now emit `og:*` and `twitter:card=summary_large_image` meta tags. Paste a media URL into Slack, Twitter, LinkedIn, or Discord and it unfurls with title, description, and the cover image - no extra plugin, no SEO setup.

## Upload modal polish

Preview tiles in the upload modal now show the filename (truncated tastefully) and a per-tile remove (×) button. Audio files get a proper audio-fallback icon instead of a broken thumbnail. The tags input gains a row of eight popular tag pills below it - click to append, no duplicates.

## Bulk Actions in admin All Media

The **Media > All Media** admin screen now supports multi-select. Tick the rows you want, pick **Move to Trash** / **Restore** / **Delete permanently** from the bulk-actions dropdown, and confirm. Context-aware - the available actions change based on whether you're viewing trashed or live media.

## Chat panel visibility setting

A new **Chat Panel Visibility** dropdown under **Media > Settings > Social** lets you scope where the floating chat panel renders: **Everywhere** (default), **WPMediaVerse pages only**, **BuddyPress pages only**, or **Disabled**. For code-level overrides there's the `mvs_should_render_chat_panel` filter.

## Global Allow Downloads toggle

A single switch under **Media > Settings > Display** hides the lightbox Download button site-wide and refuses the download REST endpoint. Pair with the per-media Allow Downloads checkbox in the Edit modal for fine-grained control.

## WCAG 2.1 AA pass

Every interactive surface added or touched in 1.2.0 was audited against WCAG 2.1 AA - `aria-label` (sentence-form) on icon buttons, `aria-pressed` on toggles, `:focus-visible` outlines on every focusable element, `role="tablist"` + `role="tab"` on segmented toggles, decorative emoji marked `aria-hidden`. Keyboard navigation works everywhere mouse navigation works.

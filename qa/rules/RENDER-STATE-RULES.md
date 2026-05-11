# Empty-State & Silent-Fallthrough Audit

> **Rule (standing):** Every render path — frontend OR backend — must either produce visible output OR emit a user-understandable empty state. A bare `return;` in a render path that leaves a blank region is a bug, regardless of intent.
>
> **Why this rule exists:** F7 (BP broken thumbnails, src = page URL), F10 (`mvs/media-player` silent for images), F11 (`mvs/lock-overlay` silent without mediaId), F12 (`mvs/story-viewer` silent when no stories), F6 (anon `/my-media/` blank page). Every one of these was a `return;` with no UI signal. Users see a dead region and can't tell if the page is broken, loading, or empty.

---

## 1. Where the rule applies

### Render paths (must emit visible state)

| Surface | File pattern | Required when "nothing to show" |
|---------|--------------|--------------------------------|
| Gutenberg block | `{src,build}/blocks/*/render.php` | `<div class="mvs-block-empty">…reason…</div>` with action hint |
| Shortcode callback | `render_*()` in `Shortcodes/Shortcodes.php` returning `string` | Non-empty string with reason + action |
| Template file | `templates/**/*.php` | Empty-state partial, same styling as populated state |
| Admin list table / panel | `includes/Admin/**/*.php` | Empty row or panel-level empty copy with primary CTA |
| Admin filter result | same | "No results for {query}" + reset-filter affordance |
| REST endpoint | `REST/Controller/*.php` | `WP_Error` with specific code + translatable message (not `return null`) |
| Frontend widget | `Frontend/**Widget*.php` | Empty state inside the widget frame, never a hollow frame |

### Non-render paths (silent `return;` is fine)

| Surface | Example | Why OK |
|---------|---------|--------|
| Action hook callback | `NotificationIntegration::on_comment_created()` when comment isn't for mvs | WP pattern — skip-unrelated-events is normal |
| Cron handler | `ChallengeService::activate_scheduled()` when nothing scheduled | Runs headless, logs via LoggerService if anything went wrong |
| Permission check | `permission_callback` returning `false` | WP contract — REST layer wraps this in a 401/403 response |
| Filter callback | returning unchanged input when filter not applicable | WP pattern |

---

## 2. Pattern to enforce

### ❌ BAD (silent render return)

```php
// build/blocks/lock-overlay/render.php
$media_id = isset( $attributes['mediaId'] ) ? absint( $attributes['mediaId'] ) : 0;
if ( ! $media_id ) {
    return;  // <-- F11: block disappears from the page, user has no idea why
}
```

### ✅ GOOD (explicit empty state)

```php
$media_id = isset( $attributes['mediaId'] ) ? absint( $attributes['mediaId'] ) : 0;
if ( ! $media_id ) {
    return self::render_block_empty_state(
        'lock-overlay',
        __( 'Lock Overlay', 'wpmediaverse' ),
        __( 'Select a media item to protect in the block settings.', 'wpmediaverse' ),
        array( 'context' => 'editor_hint' )  // hidden on frontend for readers
    );
}

if ( ! MediaRepository::exists( $media_id ) ) {
    \WPMediaVerse\Services\LoggerService::warn(
        'lock_overlay_missing_media',
        array( 'media_id' => $media_id, 'block' => 'lock-overlay' )
    );
    return self::render_block_empty_state(
        'lock-overlay',
        __( 'Lock Overlay', 'wpmediaverse' ),
        __( 'The referenced media no longer exists.', 'wpmediaverse' )
    );
}
```

### ✅ GOOD (shortcode)

```php
public function render_album( $atts ): string {
    $atts = shortcode_atts( array( 'id' => 0 ), $atts );
    $album_id = absint( $atts['id'] );

    if ( ! $album_id || ! get_post( $album_id ) ) {
        return $this->render_empty(
            __( 'Album not found', 'wpmediaverse' ),
            __( 'Pass a valid album ID via the id attribute: [mvs_album id="123"].', 'wpmediaverse' )
        );
    }
    // ... normal render
}
```

### ✅ GOOD (admin list)

```php
if ( empty( $rows ) ) {
    echo '<tr class="mvs-admin-empty-row"><td colspan="5">';
    if ( $this->has_active_filters() ) {
        printf(
            /* translators: %s: reset-filters link. */
            esc_html__( 'No results for your current filters. %s', 'wpmediaverse' ),
            '<a href="' . esc_url( $this->base_url ) . '">' . esc_html__( 'Clear filters', 'wpmediaverse' ) . '</a>'
        );
    } else {
        printf(
            /* translators: %s: "Create new" link. */
            esc_html__( 'No challenges yet. %s', 'wpmediaverse' ),
            '<a class="button button-primary" href="' . esc_url( $this->new_url ) . '">' .
                esc_html__( 'Create your first challenge', 'wpmediaverse' ) .
            '</a>'
        );
    }
    echo '</td></tr>';
    return;
}
```

---

## 3. Shared empty-state helpers (to add)

Introduce two helpers the whole codebase uses. New code is required to use them.

### `TemplateHelpers::render_block_empty_state( $slug, $title, $message, $opts = [] )`

Produces:

```html
<div class="mvs-block-empty mvs-block-empty--{slug}"
     role="status"
     aria-live="polite"
     data-mvs-empty-reason="{slug}">
    <div class="mvs-block-empty__icon"><i data-lucide="info"></i></div>
    <div class="mvs-block-empty__title">{title}</div>
    <div class="mvs-block-empty__message">{message}</div>
    {optional action button}
</div>
```

In editor context, shows to the editor with a configure-hint. On frontend, shows nothing for readers unless `$opts['show_public'] === true`, but still renders the `data-mvs-empty-reason` attribute so QA can grep the DOM for silent empties.

### `TemplateHelpers::render_admin_empty_state( $title, $message, $primary_cta = null )`

Produces admin-side empty row/panel with consistent styling.

---

## 4. Current baseline (2026-04-23 scan)

Running `grep -rn "^\s*return;$" render.php` across the plugin pair:

### Frontend — render-path silent returns

| File | Lines | Fix needed? |
|------|------:|-------------|
| `build/blocks/story-viewer/render.php` | 1 | Yes — replace with `render_block_empty_state('story-viewer', 'No active stories', 'Stories appear here when users upload with story visibility.')` |
| `build/blocks/media-stats/render.php` | 1 | Yes — should render stats with zeros (already noted; today shows hollow frame) |
| `build/blocks/album-viewer/render.php` | 2 | Yes — when no `albumId` or album missing |
| `build/blocks/media-player/render.php` | 4 | Yes — including **F10** (image media type not handled) |
| `build/blocks/media-upload/render.php` | 1 | Review — may be cap-gated; if so, render "Log in to upload" gate |
| `build/blocks/lock-overlay/render.php` | 2 | Yes — **F11** |

**Both `src/blocks/` and `build/blocks/` have identical issues** — fix source, rebuild.

### Frontend — helper fallthroughs

| File:line | Issue |
|-----------|-------|
| `includes/Core/TemplateHelpers.php:72` | `get_thumb_url()` returns `''` when file_url missing → the exact chain that produced F7 `src=""` |
| `includes/Core/TemplateHelpers.php:89` | Same — secondary size fallback returning empty |
| `includes/Shortcodes/Shortcodes.php:327, 369` | Two shortcode render methods return empty string silently |

### Pro — frontend

| File | Silent returns | Risk |
|------|---:|------|
| `Frontend/InstagramFeedService.php` | 3 | Connector feed disappears when token expired/invalid — user sees nothing |
| `Frontend/UsageWidget.php` | 3 | Quota widget disappears in unmeasured states |
| `Frontend/GamificationTemplateLoader.php` | 3 | Panel disappears when feature toggle off (may be fine — verify) |
| `Frontend/Layouts/LayoutManager.php` | 5 | **P6 root cause** — invalid slug `'default'` silently skipped layout activation |

### Backend — non-render (audit: should these log?)

| File | Silent returns | Recommended |
|------|---:|------|
| `Integrations/BuddyPress/NotificationIntegration.php` | 7 | Accept — these are skip-unrelated-event branches |
| `Integrations/BuddyPress/ActivityContentIntegration.php` | 5 | Accept |
| `Integrations/BuddyPress/ActivitySyncIntegration.php` | 6 | Accept — one exception: line 329 (activity-delete sync) — if target not found, log instead of silent skip |
| `Integrations/BuddyPress/BuddyPressManager.php` | 1 | Accept (early-exit when BP not loaded) |

---

## 5. The audit (as a CI step)

### 5.1 Static grep — fails CI on new silent render returns

Add to `.github/workflows/qa.yml`:

```yaml
- name: Silent-fallthrough scan
  run: |
    bash bin/empty-state-audit.sh
```

Script `bin/empty-state-audit.sh`:

```bash
#!/usr/bin/env bash
# Fail on NEW silent returns in render paths vs baseline.
set -euo pipefail

BASELINE="bin/empty-state-baseline.txt"
CURRENT=$(mktemp)

# Render-path globs:
FILES=$(find src/blocks build/blocks templates includes/Shortcodes includes/Admin \
  -name "render.php" -o -name "*Panel*.php" -o -name "*Widget*.php" \
  -o -name "*Page*.php" -o -name "Shortcodes.php" -o -path "*templates*.php" 2>/dev/null)

# Find bare "return;" at start of line within those files:
for f in $FILES; do
  grep -nP '^\s*return;\s*$' "$f" >> "$CURRENT" || true
done

if ! diff -q "$BASELINE" "$CURRENT" > /dev/null; then
  echo "NEW silent returns detected in render paths:"
  diff "$BASELINE" "$CURRENT"
  echo ""
  echo "Use TemplateHelpers::render_block_empty_state() or render_admin_empty_state()."
  echo "If the return is genuinely correct (action-hook skip, etc.), move it out of a render file."
  exit 1
fi
```

### 5.2 Runtime DOM scan — Playwright assertion

Every journey spec adds a post-assertion:

```js
// In e2e test helpers:
async function assertNoSilentEmpty(page) {
  const reasons = await page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-mvs-empty-reason]')).map(el => el.dataset.mvsEmptyReason)
  );
  // Silent empties from frontend renders that bypassed the helper show up as
  // zero-size blocks with no class. Detect:
  const hollow = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('[class*="wp-block-mvs-"]'))
      .filter(el => el.offsetHeight < 2 && el.innerHTML.trim() === '')
      .map(el => el.className);
  });
  expect(hollow, 'blocks rendered to invisible nothing').toEqual([]);
}
```

Call from every spec. Fails any run where an `mvs-*` block produced zero-height empty output.

### 5.3 Manual reviewer checklist

Attach to PR template:

```
[ ] No new bare `return;` in render.php / templates / shortcode handlers
[ ] If empty state is possible, it uses `render_block_empty_state()` or equivalent
[ ] Admin list pages have distinct empty vs filtered-empty copy
[ ] REST endpoints return WP_Error with code + translated message on empty/invalid, never null
[ ] New user-facing state (logged out / quota exceeded / feature disabled) has visible copy + link
[ ] Frontend disabled-Pro-feature hides cleanly (no broken CSS, no console error)
```

---

## 6. Fix priority (baseline cleanup)

| Finding | Severity | Target release |
|---|---|---|
| F7 BP thumbnails chain in `TemplateHelpers::get_thumb_url()` | already fixed | ✅ shipped |
| F10 `mvs/media-player` image-type silent | Minor | 1.1.3 — emit "Image media — use media-grid block" hint |
| F11 `mvs/lock-overlay` no mediaId silent | Minor | 1.1.3 — editor hint panel |
| F12 `mvs/story-viewer` no stories silent | Minor | 1.1.3 — "No active stories" empty state |
| `album-viewer` missing album silent | Minor | 1.1.3 |
| `media-stats` empty silent (hollow frame) | Minor | 1.1.3 |
| Pro `InstagramFeedService` token-invalid silent | Major | 1.1.3 — log + "Reconnect Flickr" admin notice |
| Pro `LayoutManager` invalid-slug silent | already fixed via DB cleanup | Add defensive `array_key_exists()` fallback in 1.1.3 |
| `Shortcodes::render_*` two empty-string returns | Minor | Review each — add reason copy |

---

## 7. What this rule does NOT cover

- **CSS `display:none`** applied conditionally — that's design, not silent-fallthrough, but watch for overuse.
- **Async loading states** (spinners) — must end in either populated or explicit empty, never stuck spinner.
- **Intentional hidden debug surfaces** behind `current_user_can('manage_options')` — fine as long as readers don't see a hole.

---

## 8. Update history

| Date | Author | Change |
|------|--------|--------|
| 2026-04-23 | Varun | Initial — rule + baseline scan (11 block returns, 14 helper/frontend returns, 5 Pro layout returns), CI script stub, 6 prioritized fixes for 1.1.3 |

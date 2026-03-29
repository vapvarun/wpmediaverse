# WPMediaVerse — Admin UX Guidelines

> Premium plugin standard. Every admin page reflects product quality.
> Reference implementation: Jetonomy settings page (installed at forums.local).

---

## Settings Page — Card-Based Sections

### HTML Structure (per card)

Every settings section renders as a card. Header band inside the card, not above it.

```html
<div class="mvs-settings-card">
    <div class="mvs-settings-card__head">
        <p class="mvs-settings-card__title">SECTION TITLE</p>
        <p class="mvs-settings-card__desc">Description of what this section configures.</p>
    </div>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label>Field Label</label></th>
            <td><input ... /></td>
        </tr>
    </table>
</div>
```

### CSS Design Tokens (match Jetonomy)

```css
:root {
    --mvs-admin-radius: 8px;
    --mvs-admin-shadow: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --mvs-admin-border: #e2e8f0;
    --mvs-admin-surface-1: #f8fafc;   /* card head background */
    --mvs-admin-surface-2: #f1f5f9;   /* row dividers */
}
```

### Card Styles

```css
.mvs-settings-card {
    background: #fff;
    border: 1px solid var(--mvs-admin-border);
    border-radius: var(--mvs-admin-radius);
    box-shadow: var(--mvs-admin-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}

.mvs-settings-card__head {
    padding: 14px 20px 12px;
    border-bottom: 1px solid var(--mvs-admin-border);
    background: var(--mvs-admin-surface-1);
}

.mvs-settings-card__title {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1e293b;
    margin: 0 0 2px;
}

.mvs-settings-card__desc {
    font-size: 13px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

/* form-table inside a card */
.mvs-settings-card .form-table { margin: 0; }
.mvs-settings-card .form-table th {
    padding: 14px 20px;
    font-size: 13px;
    font-weight: 500;
    width: 220px;
    vertical-align: middle;
}
.mvs-settings-card .form-table td { padding: 14px 20px; }
.mvs-settings-card .form-table tr {
    border-bottom: 1px solid var(--mvs-admin-surface-2);
}
.mvs-settings-card .form-table tr:last-child { border-bottom: none; }
```

### Rules

- Header band is INSIDE the card (subtle gray `#f8fafc` background)
- Bottom border separates header from fields
- Each `section_id` = one card (Competitions has 2 cards: toggles + boost pricing)
- No gap between sidebar top and first card
- Save button outside all cards at bottom with `padding-top: 0`
- Cards have 20px margin-bottom between each other
- Row dividers use `#f1f5f9` (lighter than card border)

### Eliminating the Top Gap

1. `.mvs-settings-content { padding-top: 0; }` — no extra space above first card
2. Sidebar and first card start at the same vertical baseline
3. No `<h1>` inside the settings layout (title is in the sidebar brand block)

---

## Sidebar Navigation

### Structure

```html
<aside class="mvs-settings-sidebar">
    <div class="mvs-settings-sidebar-brand">
        <span class="dashicons dashicons-admin-settings mvs-settings-brand-icon"></span>
        <div>
            <p class="mvs-settings-brand-name">WPMediaVerse</p>
            <p class="mvs-settings-brand-sub">SETTINGS</p>
        </div>
    </div>
    <nav class="mvs-settings-sidebar-nav">
        <p class="mvs-snav-group-label">GENERAL</p>
        <a class="mvs-snav-link mvs-snav-link--active" href="#">
            <span class="dashicons dashicons-admin-generic"></span> General
        </a>
        ...
    </nav>
</aside>
```

### Sidebar CSS

```css
.mvs-settings-sidebar {
    width: 240px;
    background: #fff;
    border: 1px solid var(--mvs-admin-border);
    border-radius: var(--mvs-admin-radius);
    box-shadow: var(--mvs-admin-shadow);
    position: sticky;
    top: 32px;
}

.mvs-snav-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    color: #475569;
}

.mvs-snav-link--active {
    background: #eff6ff;
    color: #2563eb;
    font-weight: 600;
}

.mvs-snav-group-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #94a3b8;
    padding: 12px 10px 4px;
}
```

---

## PHP Renderer Pattern

### Settings Page (SettingsPage.php)

The renderer must create one card per `section_id`:

```php
private function render_section_cards( array $section ): void {
    global $wp_settings_sections, $wp_settings_fields;

    foreach ( $section['section_ids'] as $sid ) {
        if ( empty( $wp_settings_fields[ $section['page_slug'] ][ $sid ] ) ) {
            continue;
        }

        $wp_section = $wp_settings_sections[ $section['page_slug'] ][ $sid ] ?? null;
        $title      = $wp_section ? $wp_section['title'] : $section['label'];
        ?>
        <div class="mvs-settings-card">
            <div class="mvs-settings-card__head">
                <p class="mvs-settings-card__title"><?php echo esc_html( strtoupper( $title ) ); ?></p>
                <?php if ( $wp_section && is_callable( $wp_section['callback'] ) ) : ?>
                    <div class="mvs-settings-card__desc">
                        <?php call_user_func( $wp_section['callback'], $wp_section ); ?>
                    </div>
                <?php endif; ?>
            </div>
            <table class="form-table" role="presentation">
                <?php $this->render_section_fields( $section['page_slug'], array( $sid ) ); ?>
            </table>
        </div>
        <?php
    }
}
```

---

## Admin Page Registration

ALL admin pages register under the WPMediaVerse menu. No hidden pages, no null parents, no hacks.

```php
add_submenu_page(
    'edit.php?post_type=mvs_media',
    __( 'Page Title', 'wpmediaverse-pro' ),
    __( 'Page Title', 'wpmediaverse-pro' ),
    'manage_mvs_settings',
    'mvs-page-slug',
    array( $this, 'render_page' )
);
```

This gives you:
- Correct browser tab title
- WPMediaVerse menu highlighted when on the page
- Page accessible from both the menu AND the Settings sidebar
- No workarounds needed

### Never Do

- `add_submenu_page( null, ... )` — breaks menu highlighting, loses page title
- `remove_submenu_page()` — breaks browser tab titles
- `parent_file` / `submenu_file` filters to fix broken registration
- Empty string as menu title

---

## Admin Page Structure

Every standalone admin page:

```php
<div class="wrap">
    <h1 class="wp-heading-inline">Page Title</h1>
    <a href="..." class="page-title-action">Create New</a>
    <hr class="wp-header-end">
    <p class="description">What this page does.</p>

    <nav class="nav-tab-wrapper wp-clearfix">
        <a class="nav-tab nav-tab-active">Active</a>
        <a class="nav-tab">Completed</a>
    </nav>

    <!-- Content -->
</div>
```

---

## Permissions Table

- Inside a `.mvs-settings-card` with header band
- Bordered table, role column 200px, capability columns center-aligned
- Checkboxes centered
- Alternating row colors

---

## Code Standards

- **Method names**: Professional. `add_menu_page`, `render_page`, `handle_save`
- **No hacky names**: Never `fix_page_title`, `filter_admin_title`, `restore_page_title`
- **PHPDoc**: Every public method
- **Textdomain**: All strings
- **Labels**: Every input
- **Nonces + capabilities**: Every form
- **WPCS**: Always

---

## Checklist Before Shipping

- [ ] Browser tab shows correct page title
- [ ] Page has h1 + description
- [ ] Empty state message
- [ ] Form has nonce + capability check
- [ ] All inputs have labels
- [ ] Responsive at 640px
- [ ] Accessible from Settings sidebar
- [ ] Hidden from menu (if manager page)
- [ ] Cards follow the design token system (border, radius, shadow, colors)
- [ ] No top gap between sidebar and first card
- [ ] Multiple sections render as separate cards

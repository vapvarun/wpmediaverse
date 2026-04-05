# SettingsPage Split — Design Spec

**Date:** 2026-04-05
**Status:** Design Approved
**Scope:** `wpmediaverse` (Free plugin only)

---

## Context

`includes/Admin/SettingsPage.php` is 2,401 lines handling settings registration, field rendering, page layout, permissions management, and sanitization. It manages 8 sidebar sections, 40+ settings, 18+ field renderer types, and a custom permissions matrix.

**Goal:** Split into 5 focused classes under `includes/Admin/Settings/`, each under 500 lines.

---

## New File Structure

```
includes/Admin/Settings/
├── SettingsPage.php         (~300 lines) — Page orchestrator: render, nav, notices, menu cleanup
├── SettingsRegistrar.php    (~500 lines) — Register all sections/fields across all tabs
├── FieldRenderer.php        (~400 lines) — All render_*_field() static methods
├── PermissionsManager.php   (~200 lines) — Role/capability matrix + save handler
└── Sanitizers.php           (~100 lines) — All sanitize_* callbacks
```

Delete: `includes/Admin/SettingsPage.php` (old location)

---

## Class Responsibilities

### SettingsPage (orchestrator, ~300 lines)
- Namespace: `WPMediaVerse\Admin\Settings`
- Constants: PAGE_SLUG, OPTION_GROUP
- Constructor registers hooks: admin_menu (add_menu_page, cleanup_admin_menu), admin_init (delegates to SettingsRegistrar), admin_notices, admin_post
- Methods kept: render_page(), get_registered_sections(), group_sections(), render_section_cards(), render_section_fields(), cleanup_admin_menu(), get_active_tab(), is_pro_active(), track_settings_changes(), render_contextual_notices(), render_storage_toggle_script(), render_pro_upsell(), get_pro_features_for_tab(), get_panel_meta()
- Instantiates SettingsRegistrar and PermissionsManager

### SettingsRegistrar (~500 lines)
- Namespace: `WPMediaVerse\Admin\Settings`
- All register_*_settings() methods: register_general_settings, register_display_settings, register_ai_settings, register_moderation_settings, register_webhook_settings, register_messaging_settings, register_watermark_settings, register_pages_settings
- Master register_all() method called from SettingsPage
- References FieldRenderer for render callbacks
- References Sanitizers for sanitize callbacks

### FieldRenderer (~400 lines)
- Namespace: `WPMediaVerse\Admin\Settings`
- All static render methods: render_number_field, render_size_field, render_file_types_field, render_textarea_field, render_select_field, render_password_field, render_checkbox_field, render_text_field, render_color_field, render_page_dropdown_field, render_webhook_field, render_pro_select_field, render_pro_checkbox_field
- Stateless — all methods are static, take args array as parameter

### PermissionsManager (~200 lines)
- Namespace: `WPMediaVerse\Admin\Settings`
- Methods: render_permissions_tab(), process_role_caps_save(), save_role_caps()
- Self-contained: own form, nonce, save handler
- Registered on admin_post_mvs_save_role_caps hook

### Sanitizers (~100 lines)
- Namespace: `WPMediaVerse\Admin\Settings`
- All static sanitize callbacks: sanitize_size_mb, sanitize_file_types, sanitize_password_option, sanitize_webhooks
- Referenced by SettingsRegistrar in register_setting() calls

---

## Service Container Update

```php
// In Plugin::register_services() — key stays the same
$container->register('admin.settings', function() {
    return new \WPMediaVerse\Admin\Settings\SettingsPage();
});
```

---

## Migration Strategy

1. Create `includes/Admin/Settings/` directory
2. Create Sanitizers.php (no dependencies)
3. Create FieldRenderer.php (no dependencies)
4. Create PermissionsManager.php (self-contained)
5. Create SettingsRegistrar.php (references FieldRenderer + Sanitizers)
6. Create SettingsPage.php (references all above)
7. Update Plugin.php import
8. Delete old `includes/Admin/SettingsPage.php`

---

## What Does NOT Change

- All option names (mvs_max_upload_size, etc.)
- All settings sections and fields
- Page slug (mvs-settings)
- Option groups
- Permissions form behavior
- Admin menu cleanup CSS
- Pro upsell rendering
- All extensibility hooks (mvs_settings_sections, mvs_settings_group_labels, etc.)

---

## Verification Plan

1. `grep -r "Admin\\\\SettingsPage" includes/` — only references new namespace
2. Browser test: Visit settings page, verify all tabs render
3. Browser test: Save settings on each tab, verify values persist
4. Browser test: Permissions tab — toggle caps, save, verify
5. `php -l` on all new files
6. Full test suite passes

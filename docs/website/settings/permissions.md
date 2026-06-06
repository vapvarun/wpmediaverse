# Permissions

Access these settings at **WPMediaVerse > Settings > Permissions**.

![Permissions tab showing role-capability matrix](../images/admin-settings-general.png)

## Custom Capabilities

WPMediaVerse registers the following custom WordPress capabilities:

| Capability | Description |
|------------|-------------|
| `upload_mvs_media` | Upload new media files |
| `edit_mvs_media` | Edit own media posts |
| `edit_others_mvs_media` | Edit media posts created by other users |
| `delete_mvs_media` | Delete own media posts |
| `delete_others_mvs_media` | Delete media posts created by other users |
| `moderate_mvs_media` | Access the moderation queue and approve/reject media |
| `manage_mvs_settings` | Manage WPMediaVerse settings (finer-grained grant checked by settings actions; the admin menu itself gates on core `manage_options`) |
| `manage_mvs_access` | Manage custom access grants for private media |
| `read_mvs_media` | View media (used for private media visibility checks) |
| `publish_mvs_media` | Publish media posts immediately (without pending review) |

## Default Role Assignments

| Role | upload | edit own | delete own | moderate | manage settings |
|------|--------|----------|------------|----------|-----------------|
| Administrator | Yes | Yes | Yes | Yes | Yes |
| Editor | Yes | Yes | Yes | Yes | No |
| Author | Yes | Yes | Yes | No | No |
| Contributor | Yes | Yes | Yes | No | No |
| Subscriber | Yes | No | No | No | No |

## Changing Permissions

1. Go to **Media > Settings > Permissions**.
2. Check or uncheck capabilities for each role.
3. Click **Save Permissions**.

The page shows how many roles were updated. If no changes were needed, it shows "No changes were needed."

## Granting Capabilities Programmatically

You can grant or revoke capabilities in your theme's `functions.php` or a custom plugin:

```php
// Grant moderation capability to a custom role.
$role = get_role( 'shop_manager' );
if ( $role ) {
    $role->add_cap( 'moderate_mvs_media' );
    $role->add_cap( 'manage_mvs_access' );
}
```

## Important Notes

- The `moderate_mvs_media` capability grants access to the moderation queue and the ability to see all media regardless of privacy level.
- The WPMediaVerse admin menu pages (Settings, Moderation, Logs, etc.) are registered against the core `manage_options` capability. The `manage_mvs_settings` capability is a finer-grained grant some settings actions and dashboard links check, so it can be assigned to non-administrator roles that should manage WPMediaVerse without full `manage_options` access.
- Capabilities are stored in the WordPress `wp_user_roles` option and persist after plugin deactivation.

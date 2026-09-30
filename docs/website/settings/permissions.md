# Permissions

Since MediaVerse 2.6.0 there is no Permissions tab. Fine-grained role permissions no longer have a screen. The two questions site owners actually ask each have one simple control:

- **Who can upload media?** Go to **MediaVerse > Settings > General > Who can upload media**. Tick the roles that may upload. Administrators can always upload, so their box is ticked and locked. Unticking a role deletes nothing. See [General Settings](general.md).
- **Who can use documents?** (MediaVerse Pro) Go to **MediaVerse > Settings > Documents > Who can use documents**.

## Your existing choices keep working

Role choices you saved on the old Permissions tab are stored on the WordPress roles themselves, so they stay in force after the update. Nothing is reset. The "Who can upload media" boxes read the roles directly, so they always show who can really upload.

## Custom Capabilities

MediaVerse still registers these WordPress capabilities:

| Capability | Description |
|------------|-------------|
| `upload_mvs_media` | Upload new media files (set by "Who can upload media") |
| `edit_mvs_media` | Edit own media posts |
| `edit_others_mvs_media` | Edit media posts created by other users |
| `delete_mvs_media` | Delete own media posts |
| `delete_others_mvs_media` | Delete media posts created by other users |
| `moderate_mvs_media` | Access the moderation queue and approve/reject media |
| `manage_mvs_settings` | Manage MediaVerse settings (a finer-grained grant checked by settings actions; the admin menu itself gates on core `manage_options`) |
| `manage_mvs_access` | Manage custom access grants for private media |
| `read_mvs_media` | View media (used for private media visibility checks) |
| `publish_mvs_media` | Publish media posts immediately (without pending review) |

## Changing other capabilities in code

To give a role any capability other than upload, add it in your theme's `functions.php` or a small custom plugin:

```php
// Grant moderation capability to a custom role.
$role = get_role( 'shop_manager' );
if ( $role ) {
    $role->add_cap( 'moderate_mvs_media' );
    $role->add_cap( 'manage_mvs_access' );
}
```

A role editor plugin works too.

## Important Notes

- The `moderate_mvs_media` capability grants access to the moderation queue and the ability to see all media regardless of privacy level.
- The MediaVerse admin pages (Settings, Moderation, Logs and so on) are registered against the core `manage_options` capability. `manage_mvs_settings` can be given to other roles that should manage MediaVerse without full `manage_options` access.
- Capabilities are stored in the WordPress `wp_user_roles` option and persist after plugin deactivation.

# GDPR & Privacy Compliance

> **Included in Free** — This feature is available in the free version of WPMediaVerse.


WPMediaVerse integrates with the WordPress privacy tools built into **Tools > Export Personal Data** and **Tools > Erase Personal Data**. No configuration is required. The integration is active whenever the plugin is active.

The privacy functionality lives in `GDPRService.php`.


## What Gets Exported

When an administrator runs a personal data export for a user, WPMediaVerse adds the following data groups to the export ZIP:

| Data Group | What Is Included |
|------------|-----------------|
| Media uploads | File URLs, titles, descriptions, privacy level, upload date |
| Comments | All comments the user posted on media items |
| Reactions | Each reaction (emoji, media item, timestamp) |
| Favorites | Each media item the user saved as a favorite |
| Direct messages | All conversation participants and message content |
| Follow relationships | List of users the subject follows and users following the subject |

The export respects the WordPress standard format. Each group appears as a named section in the downloadable HTML and JSON files.

## What Gets Erased

When an administrator runs a personal data erasure for a user, WPMediaVerse removes:

- All media items uploaded by that user (files and database records)
- All comments posted by that user on media items
- All reactions and favorites
- All direct message conversations and messages where the user is a participant
- All follow relationships (both directions)

Erasure is permanent and cannot be undone. Media items that belong to BuddyPress groups are also removed.

> Before erasing, WordPress asks the user to confirm the request via email. WPMediaVerse erasure only runs after that confirmation is received.

## Privacy Policy Text

WPMediaVerse registers suggested privacy policy text via `wp_add_privacy_policy_content()`. The suggestion appears in the **Privacy Policy Guide** at **Settings > Privacy > Privacy Policy Guide**.

The suggested text describes:

- What media metadata is collected and stored
- How direct messages are stored and for how long
- What follow data is retained
- How to request export or erasure

You are not required to use the suggested text verbatim. Review it and incorporate the relevant parts into your site's privacy policy.


## Developer Notes

### Adding Custom Data to the Export

Use the `mvs_privacy_export_data` filter to add plugin-extension data to the exporter:

```php
add_filter( 'mvs_privacy_export_data', function( $data, $user ) {
    $data['my_extension'] = [
        'group_id'    => 'my-extension-data',
        'group_label' => __( 'My Extension Data', 'my-extension' ),
        'items'       => get_my_extension_data_for_user( $user->ID ),
    ];
    return $data;
}, 10, 2 );
```

### Hooking into Erasure

Use the `mvs_privacy_before_erase` action to perform additional cleanup before WPMediaVerse deletes user data:

```php
add_action( 'mvs_privacy_before_erase', function( $user_id ) {
    // Remove any extension data for this user.
    delete_user_meta( $user_id, 'my_extension_meta' );
} );
```

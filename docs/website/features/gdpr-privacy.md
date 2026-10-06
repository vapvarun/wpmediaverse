# GDPR & Privacy Compliance

> **Included in Free** - This feature is available in the free version of MediaVerse.


MediaVerse integrates with the WordPress privacy tools built into **Tools > Export Personal Data** and **Tools > Erase Personal Data**. No configuration is required. The integration is active whenever the plugin is active.


## What Gets Exported

When an administrator runs a personal data export for a user, MediaVerse adds the following data groups to the export ZIP:

| Data Group | What Is Included |
|------------|-----------------|
| Media uploads | File URLs, titles, descriptions, privacy level, upload date |
| Reactions | Each reaction (type, media item, date) |
| Favorites | Each media item the user saved as a favorite |
| Following | The users the subject follows |
| Everything else MediaVerse stores about the member | One section per data type, such as media views, activity, access grants, mentions, blocks, notifications, push device tokens, conversations, messages, message reactions, the usage ledger and the error log. With MediaVerse Pro active, its member data is included too. |

Data that is kept after erasure (see below) is exported too, with the reason it is kept written next to it.

The export respects the WordPress standard format. Each group appears as a named section in the downloadable HTML and JSON files.

## What Gets Erased

When an administrator runs a personal data erasure for a user, MediaVerse removes the member's data from every place it keeps it, including:

- Media uploads (files and database records), media views and activity
- Favorites, reactions, mentions and access grants
- Follows and blocks
- Notifications and push device tokens
- Messages, message reactions and the conversations the member is in
- The error log entries about the member

Erasure is permanent and cannot be undone. WordPress is told the request is finished only when nothing is left to remove.

A few records are kept, with the member's name removed, and the erasure result says so:

| Kept record | Why |
|-------------|-----|
| Reports filed by or about the member | Moderation evidence. |
| Usage ledger | Records the site owner may be legally required to keep. |
| Conversations the member started that other members are still part of | Erasing the creator must not delete the thread for everyone else. |

> Before erasing, WordPress asks the user to confirm the request via email. MediaVerse erasure only runs after that confirmation is received.

## Privacy Policy Text

MediaVerse registers suggested privacy policy text via `wp_add_privacy_policy_content()`. The suggestion appears in the **Privacy Policy Guide** at **Settings > Privacy > Privacy Policy Guide**.

The suggested text describes:

- What media details and photo location data are collected and stored
- That reactions, comments, follows, favorites, mentions and direct messages are stored
- That views are recorded, and that images are sent to OpenAI if you turn on AI tagging or moderation
- That push device tokens are stored for the mobile app
- What erasure removes and what it keeps

You are not required to use the suggested text verbatim. Review it and incorporate the relevant parts into your site's privacy policy.


## Developer Notes

MediaVerse registers its exporters and erasers through the standard WordPress privacy hooks. It does not add its own wrapper filters - extend the export/erase flow with the WordPress-core filters directly.

### Adding Custom Data to the Export

Register your own exporter via WordPress core's `wp_privacy_personal_data_exporters` filter:

```php
add_filter( 'wp_privacy_personal_data_exporters', function( $exporters ) {
    $exporters['my-extension'] = [
        'exporter_friendly_name' => __( 'My Extension Data', 'my-extension' ),
        'callback'               => 'my_extension_export_callback',
    ];
    return $exporters;
} );
```

### Hooking into Erasure

Register your own eraser via WordPress core's `wp_privacy_personal_data_erasers` filter:

```php
add_filter( 'wp_privacy_personal_data_erasers', function( $erasers ) {
    $erasers['my-extension'] = [
        'eraser_friendly_name' => __( 'My Extension Data', 'my-extension' ),
        'callback'             => 'my_extension_erase_callback',
    ];
    return $erasers;
} );
```

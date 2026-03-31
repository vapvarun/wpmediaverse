# BuddyPress Integration Overview

> **Included in Free** — BuddyPress integration works with the free version of WPMediaVerse.


WPMediaVerse integrates with BuddyPress to add media features directly into the BuddyPress social layer. The integration is automatic — no configuration needed. It activates when BuddyPress is active and gracefully skips all BP-specific code when BuddyPress is not installed.

## Requirements

- BuddyPress 12.0+
- WPMediaVerse 1.0.0+

## What the Integration Adds

| BuddyPress Component | Feature Added |
|---------------------|--------------|
| Activity | Records activity on media upload, comment, and album additions |
| Member Profiles | Adds a **Media** tab showing the member's uploads |
| Groups | Adds a **Media** tab in group navigation |
| Notifications | Sends notifications for reactions, comments, and @mentions |
| Activity Post Form | Adds a media attach button to the activity update form |

## Activation Check

The integration loads via `BuddyPressIntegration::init()`, which is called on `plugins_loaded`. Every feature check begins with:

```php
if ( ! function_exists( 'buddypress' ) ) {
    return;
}
```

Individual features additionally check `bp_is_active( 'activity' )`, `bp_is_active( 'groups' )`, and `bp_is_active( 'notifications' )` before hooking.

## Multisite Compatibility

The BuddyPress integration works on WordPress Multisite networks. Activity and notifications are scoped to the site where BuddyPress is installed. Media uploaded on a subsite is recorded in that subsite's activity stream.

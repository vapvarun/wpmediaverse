# Notifications

Social activity on a member's media raises BuddyNext notifications, so reactions and comments reach people in the same place as the rest of their community activity.

## What raises a notification

| Event | MediaVerse hook |
|---|---|
| Someone reacts to your media | `mvs_reaction_added` |
| Someone comments on your media | `mvs_comment_created` |
| Someone mentions you | `mvs_mentions_created` |
| Someone follows you | `mvs_user_followed` |
| Someone favourites your media | `mvs_favorite_toggled` |
| You receive a message | `mvs_message_sent` |

BuddyNext listens to each of these and creates the notification. MediaVerse does not write BuddyNext notification rows itself - it announces that something happened and lets BuddyNext decide how to present it.

## Privacy

A notification never reveals media the recipient cannot open. The same visibility gate that governs the feed applies, so a notification about a file whose privacy has since been tightened resolves to nothing rather than leaking a title.

## Turning them off

Notification delivery and per-channel preferences are BuddyNext settings. MediaVerse has no separate notification switch for these events; disabling the integration aspect in BuddyNext stops them at the source.

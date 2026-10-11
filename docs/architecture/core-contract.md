# MediaVerse core contract

Free core owns every rule below. Everything else (the BuddyPress integration,
Pro modules, blocks, templates, BuddyNext and any add-on) is an extension: it
renders results and calls these doors. It never computes the rule itself. To
change a behaviour, use the filter at the decision point; to fix a bug, fix the
owner and delete any copy.

`bin/coding-rules-check.sh` enforces the boundary (Rules 11 and 12, baseline in
`audit/cleanup/code-standard-baseline.txt`): new SQL outside a repository, new
privacy SQL or privacy writes outside their owners, new `wp_mail()` outside
`EmailService` and new upload requests outside `mvs-rest.js` fail the build.

## Who can see an item

| Door | Use it for | Filters |
|---|---|---|
| `PrivacyService::can_view( $media_id, $user_id )` | One item: page, REST item, lightbox, download. | `mvs_privacy_can_view` |
| `PrivacyService::safety_refuses( $media_id, $user_id, $args )` | Block, moderation and unpublished checks that no privacy grant may override (Pro documents call it). | `mvs_safety_refuses` |
| `PrivacyService::can_list( $media_id, $viewer_id )` | One item in a list (adds blocks both ways). | |
| `MediaRepository::query( array( 'privacy' => 'explore'\|'visible'\|'profile'\|'public' ) )` | Lists. `explore` = site-wide feed, `visible` = a member's list, `profile` = one author. | `mvs_explore_query_args` |
| `MediaRepository::explore_privacy_clause()` / `member_privacy_clause()` | Raw SQL fragment for a query that joins other tables. | |
| `PrivacyService::level_audience_sql( $level_expr, $author_expr, $viewer )` | Stores that keep a numeric level (BuddyPress activity). | `mvs_privacy_level_audience_sql` |
| `PrivacyService::query_listable_spaces()` | Album and collection lists. | |
| `PrivacyService::are_friends()` / `friend_ids()` | The `friends` level, for checks and lists. A community plugin answers with its own connections. | `mvs_are_friends`, `mvs_friend_ids` |
| `PrivacyService::media_drive_access( $type, $drive_id, $user )` | Access to a space or team drive. | `mvs_document_drive_access`, `mvs_media_drive_access` |

## Changing privacy

| Door | Use it for | Filters |
|---|---|---|
| `PrivacyService::apply_requested_privacy( $media_id, $privacy )` | Every privacy change: REST, bulk, Pro, cron, WP-CLI, other plugins. Applies the album rule and the chat-file lock; needs no signed-in user. | `mvs_requested_privacy` |
| `PrivacyService::map_external_privacy( $word, $vocabulary, $context )` | Turning another plugin's privacy word into a MediaVerse level. Unknown words become `private`. | `mvs_external_privacy_map`, `mvs_map_external_privacy` |
| `MediaRepository::move_to_drive( $media_id, $type, $drive_id, $folder_id )` | Moving an item between drives. Location only; privacy stays with `apply_requested_privacy()`. | `mvs_can_move_to_drive`, action `mvs_media_moved_to_drive` |

Moderation's forced `private` and the Migrator are the only direct privacy writers outside the repository and `PrivacyService`.

## Who may edit an item

`MediaCapabilities::can_edit_media( $media_id, $user_id )`: the owner when their role edits media, anyone else with edit-others. Media edit, captions, chapters and Pro privacy all ask it. Filter `mvs_can_edit_media`.

## Albums

`AlbumService::album_of( $media_id )` is the photo's album (one album per photo). `albums_for_media()` lists memberships for "what does deleting this affect".

## Uploading (JavaScript)

| Door | Use it for |
|---|---|
| `window.mvsRest.uploadMedia( url, file, fields, onProgress )` | Every upload: media, chat (`messages/upload`), documents (`proUrl( 'documents/upload' )`), replace-file. `fields`: title, description, privacy, tags, mediaGroup + groupPosition, poster, extra (context fields such as a group id), signal (cancel). Adds the video poster. |
| `window.mvsRest.captureVideoFrame( file )` | The poster frame, when a surface also shows it as a preview. |
| `window.mvsRest.proUrl( path )` | A Pro REST URL. |
| `assets/js/frontend/album-upload.js` | Uploading into an album; a surface passes context fields in `data-upload-extra`. |

## Media URLs

| Door | Use it for | Filters |
|---|---|---|
| `MediaUrl::thumb()` / `TemplateHelpers::get_thumb_url()` | A signed thumbnail; a visitor gets `''` for media they cannot see. | |
| `MediaRepository::get_permalink()` / `id_from_permalink()` | An item's permalink, and back. | `mvs_media_permalink`, `mvs_media_id_from_permalink` |
| `GET /mvs/v1/media/{id}/spaces` | The spaces an item is linked into that the viewer may open. | |

## Member emails

`EmailService::types()` is the list of member emails the owner can switch (Settings > Emails). Register a type through `mvs_email_types`, then send with `EmailService::send_member_email( $user_id, $type, $subject, $body )`: it applies the owner switch, the member's unsubscribe, BuddyNext sending its own mail, the unsubscribe link and the From header.

## Messaging

| Door | Use it for | Filters |
|---|---|---|
| `MessagingService::get_total_unread()` / `GET mvs/v1/me/messages/unread-count` | Unread badge. | |
| `MessagingService::get_participant_role( $conversation_id, $user_id )` | Whether someone is an active participant (realtime channel auth). | |
| `MessagingService::get_message_senders( $ids )` | Senders for a page of messages. | |
| `MessagingService::max_group_participants()` | Group size, checked on create, add and rejoin. | `mvs_group_conversation_max_participants` |

## Notifications

`NotificationService` renders every MediaVerse notification; change wording or links through `mvs_notification_message` and `mvs_notification_link`, never by muting and re-sending.

## Erasing a member

`MemberDataMap` lists every member-keyed table (extend it with `mvs_member_erase_map` / `mvs_member_retain_map`). `MemberPurger::purge()` erases or anonymises from that map. Account deletion and the privacy eraser both run it, until nothing is left; media goes through `MediaRepository::delete_cascade()` so files go too.

## Pro

| Door | Use it for | Filters |
|---|---|---|
| `Support\CompetitionMedia::attach_to_entry()` | Recording a battle, challenge or tournament submission. | action `mvs_pro_competition_entry_media` |
| `Support\CompetitionMedia::cover_url()` | A competition card's cover. | `mvs_pro_competition_cover_url` |

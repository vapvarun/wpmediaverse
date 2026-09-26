# Settings Reference

Every option MediaVerse and MediaVerse Pro register, with its option key, type and default.

The other pages in this section explain settings in the order you meet them in the admin screens. This page is the complete index: use it when you need to set an option in code, seed a site with WP-CLI, or check what a value defaults to before you change it.

Option keys are stored as normal WordPress options, so anything here can be read or written with `get_option()` / `update_option()` or the WP-CLI equivalents:

```bash
wp option get mvs_default_privacy
wp option update mvs_items_per_page 24
```

> **Defaults apply only when the option row is absent.** Saving a settings screen writes every field shown on that screen, including the ones you did not touch. It never changes a setting that is hidden or has no screen control. After the first save an option holds a real stored value, and changing the default in a later release will not move it.

## Free options

Registered by `Admin\Settings\SettingsRegistrar`, `GeneralSettingsRegistrar`, `DisplaySettingsRegistrar`, `AiSettingsRegistrar` and `AppSettingsRegistrar`.

Rows marked "no screen control since 2.6.0 (set in code/WP-CLI)" are still registered and still work. They were taken off the screen because almost every site wants the default.

### Uploads and storage

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_storage_limit_mb` | Fair-use storage limit per member (MB) | integer | `0` (no limit). Per-member override: user meta `mvs_storage_limit_mb` (absent = site limit, 0 = no limit). Filter: `mvs_storage_limit_bytes` |
| `mvs_max_upload_size` | Max Upload Size | integer | `104857600` (100 MB, in bytes) |
| `mvs_allowed_file_types` | Allowed File Types | string | `image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg` |
| `mvs_duplicate_action` | Duplicate Detection | string | `warn` |
| `mvs_strip_exif` | Remove location from photos | boolean | `true` |
| `mvs_delete_data_on_uninstall` | Remove Data on Delete | boolean | `false` |
| `mvs_storage_driver` | Where files are stored (Storage tab) | string | `local` |
| `mvs_optimize_originals` | Compress uploaded images (Storage tab) | boolean | `false` |
| `mvs_signed_url_ttl` | Signed URL Expiry (seconds) - no screen control since 2.6.0 (set in code/WP-CLI) | integer | `3600` |
| `mvs_view_retention_days` | View Event Retention (days) - no screen control since 2.6.0 (set in code/WP-CLI) | integer | `90` |
| `mvs_filename_strategy` | Stored Filenames - no screen control since 2.6.0 (set in code/WP-CLI) | string | `hashed` |
| `mvs_generate_webp` | Create WebP copies - no screen control since 2.6.0 (set in code/WP-CLI) | boolean | `true` |
| `mvs_generate_avif` | Create AVIF copies - no screen control since 2.6.0 (set in code/WP-CLI) | boolean | `false` |
| `mvs_cloud_direct_public_urls` | *(no settings field; inert since 1.4.0 - public cloud media is served directly automatically, this value is ignored)* | boolean | `false` |

`mvs_duplicate_action` accepts `warn` (allow the upload and warn), `skip` (shown as "Block the upload") or `allow` (skip the hash check entirely). `mvs_strip_exif` removes only the GPS location from new uploads; camera details and photo credits stay, and files already stored are not rewritten.

`mvs_storage_driver` accepts `local`, `s3`, `bunnycdn`, `r2` or `dospaces`. Cloud drivers need MediaVerse Pro.

### Privacy and permissions

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_default_privacy` | Default Privacy Level | string | `public` |
| `mvs_allow_user_privacy` | Allow Users to Set Privacy | boolean | `true` |
| `mvs_upload_roles` | Who can upload media | array | *(empty array)* |
| `mvs_allow_downloads` | Allow Downloads (Display tab) | boolean | `true` |

`mvs_default_privacy` is the level applied when the uploader makes no choice. When `mvs_allow_user_privacy` is off, the privacy select is removed from the upload form and every upload takes the default.

`mvs_upload_roles` is only the transport for the "Who can upload media" checkboxes. The real state is the `upload_mvs_media` capability on each role: saving the field adds or removes that capability, and the field reads the roles back. Administrators can always upload. Reading this option does not tell you who can upload; check the roles instead.

### Mobile App

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_app_password_login` | App Sign-In | boolean | `true` |
| `mvs_terms_url` | Terms of Service URL | string | *(empty)* |
| `mvs_abuse_contact_email` | Abuse Contact Email | string | *(empty - uses the site admin email)* |
| `mvs_eula_url` | EULA URL - no screen control since 2.6.0 (set in code/WP-CLI) | string | *(empty - standard Apple licence)* |

All four are on the **Mobile App** tab since 2.6.0. App Sign-In moved there from General, and the Terms of Service URL and Abuse Contact Email moved from Moderation.

`mvs_app_password_login` controls whether members may exchange their WordPress password for an Application Password to sign in to a mobile app. Turn it off when you require everyone through the interactive login - for example when you enforce two-factor authentication. Also filterable at runtime via `mvs_app_password_login_enabled`.

### Display

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_layout_choice` | Layout | string | `original` |
| `mvs_thumbnail_style` | Layout (Free choices) | string | `original` |
| `mvs_grid_columns` | Grid Columns | integer | `3` |
| `mvs_items_per_page` | Items Per Page | integer | `12` |
| `mvs_thumbnail_size` | Thumbnail Quality - no screen control since 2.6.0 (set in code/WP-CLI) | string | `large` |
| `mvs_large_image_size` | Large Image Size - no screen control since 2.6.0 (set in code/WP-CLI) | integer | `1024` |
| `mvs_lightbox_image_source` | Lightbox Image Size - no screen control since 2.6.0 (set in code/WP-CLI) | string | `large` |

`mvs_layout_choice` is only the transport for the one **Layout** select. A Free choice (`square`, `original`, `list`) is written to `mvs_thumbnail_style` and puts the Pro feed back on `grid`. A Pro skin is written to `mvs_pro_feed_layout`. To set the layout in code, write `mvs_thumbnail_style` (and `mvs_pro_feed_layout` with Pro). The default of `original` can be changed with the `mvs_default_thumbnail_style` filter.

`mvs_large_image_size` is the long-edge pixel width of the generated `large` variant. `mvs_lightbox_image_source` chooses which file the lightbox opens: the `large` variant (default, faster on phones) or `original` for full quality.

### Messages

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_messaging_enabled` | Messages (turn on private messages) | boolean | `true` |
| `mvs_dm_access` | Who can send messages | string | `everyone` |
| `mvs_dm_min_age` | Minimum Account Age (days) | integer | `0` |
| `mvs_chat_panel_visibility` | Chat Panel Visibility | string | `everywhere` |
| `mvs_show_online_status` | Online Status Visibility | string | `everyone` |
| `mvs_comment_edit_window` | *(no settings field - filterable)* | integer | `900` (15 minutes) |

`mvs_dm_access` accepts `everyone`, `followers`, `mutual` or `nobody` (shown as "Nobody (no new messages; existing conversations stay readable)"; to switch messaging off entirely, untick `mvs_messaging_enabled`). This is the only place the word "followers" appears as a setting value - it controls who may open a conversation with a member, and has nothing to do with media privacy.

`mvs_comment_edit_window` is the number of seconds after posting during which a member may still edit their own comment.

### Moderation and reporting

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_guidelines_url` | Community Guidelines URL | string | *(empty)* |
| `mvs_enable_reports` | Member Reporting | boolean | `true` |
| `mvs_report_auto_hide_threshold` | Auto-Hide Threshold | integer | `3` |
| `mvs_ai_auto_moderate` | AI Moderation | boolean | `false` |
| `mvs_moderation_auto_action` | When AI Flags Content | string | `flag` |
| `mvs_ai_moderation_categories` | AI Flag Criteria | array | `nudity`, `violence`, `hate`, `self-harm`, `drugs`, `spam` |
| `mvs_ai_moderation_custom_terms` | Custom Flag Terms | string | *(empty)* |

`mvs_report_auto_hide_threshold` is the number of distinct reports after which a media item is hidden automatically pending review. `mvs_moderation_auto_action` accepts `flag` (hide until reviewed), `reject` or `delete`. The last three rows show only while AI Moderation is on.

`mvs_ai_moderation_categories` falls back to the full category list when stored empty, so an empty value never silently disables flagging.

### AI

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_ai_provider` | AI Provider | string | `openai` |
| `mvs_openai_api_key` | OpenAI API Key | string | *(empty)* |
| `mvs_openai_model` | OpenAI Model - no screen control since 2.6.0 (set in code/WP-CLI) | string | `gpt-4o-mini` |
| `mvs_ai_auto_analyze` | Auto-Analyze Uploads | boolean | `false` |
| `mvs_ai_auto_describe` | Generate Descriptions | boolean | `true` |
| `mvs_ai_auto_tag` | Generate Tags | boolean | `true` |
| `mvs_ai_auto_apply_tags` | Auto-Apply Tags | boolean | `false` |
| `mvs_ai_monthly_budget` | Monthly AI Budget ($) | number | `10` |
| `mvs_ai_cost_per_call` | *(no settings field - filterable)* | number | `0.01` |

Note the pairing: `mvs_ai_auto_tag` decides whether tags are *generated*, and `mvs_ai_auto_apply_tags` decides whether they are *attached to the media item automatically* rather than suggested for review. Generating without applying is the safe starting configuration.

`mvs_ai_monthly_budget` combined with `mvs_ai_cost_per_call` drives the spend estimate and the budget cutoff. `0` means no limit.

### Emails

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_email_battle_invite` | Photo battle invites | boolean | `false` (a new install turns it on) |
| `mvs_email_document_shared` | Documents shared with a member | boolean | `false` (a new install turns it on) |
| `mvs_email_report_outcome` | Report reviewed | boolean | `false` (a new install turns it on) |

### Pages

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_page_dashboard` | My Media page | integer | `0` |
| `mvs_page_explore` | Explore Page | integer | `0` |
| `mvs_page_upload` | Upload Page | integer | `0` |
| `mvs_page_explore_documents` | Explore Documents Page | integer | `0` |

### Webhooks

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_webhooks` | Webhook Configuration | array | *(empty array)* |

See [Webhooks](webhooks.md) for the payload format and the event list.

## Pro options

Registered by `Admin\ProSettings` and `Admin\GamificationSettings`. All Pro options are inert unless MediaVerse Pro is active.

### Feature toggles

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_stories_enabled` | Stories (Display tab) | string | `0` |
| `mvs_connectors_enabled` | Turn on Flickr import (Flickr import tab) | string | `0` |
| `mvs_streaks_enabled` | Enable Streaks | string | `0` |
| `mvs_watermark_enabled` | Enable Watermark (Storage tab) | boolean | `false` |
| `mvs_autopilot_enabled` | Enable Autopilot | string | `0` |

Toggles stored as strings compare against `'1'`. When a feature is off its service is never constructed, so its REST routes are not registered and its scheduled actions do not run - which is why a disabled feature's endpoints return 404 rather than 403.

The gamification toggles `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled` and `mvs_boosts_enabled` follow the same pattern and are covered in [Gamification](../gamification/overview.md).

### AI providers

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_google_vision_key` | Google Cloud API Key | string | *(empty)* |
| `mvs_pro_anthropic_key` | Anthropic API Key | string | *(empty)* |
| `mvs_pro_anthropic_model` | Claude Model - no screen control since 2.6.0 (set in code/WP-CLI) | string | `claude-haiku-4-5-20251001` |

Only the key card for the selected AI provider is shown.

### Gamification

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_battle_win_xp` | Battle win reward (points) | integer | `100` |
| `mvs_pro_boost_cost_per_100` | Points per 100 Impressions | integer | `50` |
| `mvs_pro_boost_max_impressions` | Max Impressions per Boost | integer | `5000` |
| `mvs_pro_boost_expiry_days` | Boost Expiry (Days) | integer | `7` |
| `mvs_streak_freezes_enabled` | Allow Streak Freezes | string | `0` |
| `mvs_pro_streak_freeze_cost` | Freeze Cost (Points) | integer | `100` |

On the Competitions tab each competition type row shows only while Competitions is on. Boost pricing shows only while Media Boosts is on, Allow Streak Freezes only while Streaks is on, and Freeze Cost only while freezes are on. The key `mvs_pro_battle_win_xp` keeps its old name; the value is points.

### Challenge autopilot

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_autopilot_day` | Day of Week | string | `monday` |
| `mvs_autopilot_hour` | Time | integer | `9` |
| `mvs_autopilot_entry_days` | Entry Period (Days) | integer | `7` |
| `mvs_autopilot_voting_days` | Voting Period (Days) | integer | `3` |
| `mvs_autopilot_max_entries` | Max Entries per User | integer | `1` |

Autopilot creates the next themed challenge from Challenge Themes on the configured day and hour. `mvs_autopilot_entry_days` and `mvs_autopilot_voting_days` set the length of each phase, so a challenge's full cycle is the sum of the two. Weekly Autopilot shows only while Photo Challenges is on, and these rows only while Enable Autopilot is on. The autopilot points awards (`mvs_autopilot_xp_*`) sit under **Points rewards**.

### Flickr import and layout

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_connector_flickr_app_key` | Flickr Plugin API Key | string | *(empty)* |
| `mvs_pro_connector_flickr_app_secret` | Flickr Plugin API Secret | string | *(empty)* |
| `mvs_pro_feed_layout` | Layout (Pro skins, Display tab) | string | `grid` |

The Flickr key, secret and cards show only while **Turn on Flickr import** is ticked. `mvs_pro_feed_layout` has no control of its own since 2.6.0: picking a Pro skin (`instagram`, `pinterest`, `flickr`, `dribbble`) in the one Display > Layout select writes it, and picking a Free layout sets it back to `grid`.

### Mobile App Branding

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_app_accent_color` | Accent Color | string | *(empty - the app's own colour)* |
| `mvs_app_logo_id` | App Logo | integer | `0` |
| `mvs_app_login_bg_id` | Login Background | integer | `0` |
| `mvs_app_dark_mode_default` | Default to Dark Mode | boolean | `false` |

On the **Mobile App** tab since 2.6.0 (moved from Display).

### Image watermarking

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_watermark_apply` | Apply to | string | `all` |
| `mvs_watermark_roles` | Watermark uploads from | array | *(empty array)* |
| `mvs_watermark_type` | Watermark Type | string | `text` |
| `mvs_watermark_text` | Watermark Text | string | *(site name)* |
| `mvs_watermark_image_id` | Watermark Image | integer | `0` |
| `mvs_watermark_position` | Position | string | `bottom-right` |
| `mvs_watermark_opacity` | Opacity (%) | integer | `40` |
| `mvs_watermark_font_size` | Text Size | integer | `24` |
| `mvs_watermark_color` | Text Color | string | `#ffffff` |

On the **Storage** tab since 2.6.0 (moved from Display). These rows show only while Enable Watermark is ticked.

### Other

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_settings` | *(container option)* | array | *(empty array)* |

`mvs_pro_settings` is a single array option holding assorted Pro values that have no dedicated key, such as the auto-captions switch (`captions_auto`). Read it with `get_option( 'mvs_pro_settings', array() )` rather than assuming a shape.

Documents settings (Enable Documents, Who can use documents, Maximum document size, Allowed file types, New documents start as, Share links that work without signing in, Search inside documents) are on the **Documents** tab. Every row except Enable Documents shows only while it is on. The orphaned document files tool moved to **MediaVerse > Documents** in 2.6.0.

## Storing credentials in `wp-config.php`

API keys and cloud credentials can be defined as constants instead of being stored in the database. When a constant is defined, the matching settings field renders locked and shows "Defined in wp-config.php", and the stored option is ignored.

This keeps secrets out of database dumps and out of staging copies. See [Cloud Storage](../pro-features/cloud-storage.md) for the Cloudflare R2, S3 and DigitalOcean Spaces constant names.

## Related

- [General Settings](general.md) - the General tab, explained in context
- [Display Settings](display.md)
- [Permissions](permissions.md)
- [AI & Moderation](ai-moderation.md)
- [Messages Settings](social.md)
- [Webhooks](webhooks.md)
- [Hooks & Filters Reference](../developer-guide/hooks-filters.md) - filters that override these values at runtime

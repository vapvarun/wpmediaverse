# Settings Reference

Every option WPMediaVerse and WPMediaVerse Pro register, with its option key, type and default.

The other pages in this section explain settings in the order you meet them in the admin screens. This page is the complete index: use it when you need to set an option in code, seed a site with WP-CLI, or check what a value defaults to before you change it.

Option keys are stored as normal WordPress options, so anything here can be read or written with `get_option()` / `update_option()` or the WP-CLI equivalents:

```bash
wp option get mvs_default_privacy
wp option update mvs_items_per_page 24
```

> **Defaults apply only when the option row is absent.** Saving a settings screen writes every field on that screen, including the ones you did not touch. After the first save an option holds a real stored value, and changing the default in a later release will not move it.

## Free options

Registered by `Admin\Settings\SettingsRegistrar` and `Admin\Settings\AiSettingsRegistrar`.

### Uploads and storage

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_max_upload_size` | Max Upload Size | integer | `104857600` (100 MB, in bytes) |
| `mvs_allowed_file_types` | Allowed File Types | string | `image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg` |
| `mvs_duplicate_action` | Duplicate Detection | string | `warn` |
| `mvs_strip_exif` | Strip EXIF Data | boolean | `true` |
| `mvs_storage_driver` | Storage Driver | string | `local` |
| `mvs_signed_url_ttl` | Signed URL Expiry (seconds) | integer | `3600` |
| `mvs_cloud_direct_public_urls` | *(no settings field - set in code)* | boolean | `false` |

`mvs_duplicate_action` accepts `warn` (allow the upload and warn), `skip` (reject the duplicate) or `allow` (skip the hash check entirely). `mvs_strip_exif` removes GPS and device data from JPEGs at upload time; it does not rewrite files already stored.

### Privacy and permissions

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_default_privacy` | Default Privacy Level | string | `public` |
| `mvs_allow_user_privacy` | Allow Users to Set Privacy | boolean | `true` |
| `mvs_allow_downloads` | Allow Downloads | boolean | `true` |
| `mvs_app_password_login` | App Sign-In | boolean | `true` |

`mvs_app_password_login` controls whether members may exchange their WordPress password for an Application Password to sign in to a mobile app. Turn it off when you require everyone through the interactive login - for example when you enforce two-factor authentication. Also filterable at runtime via `mvs_app_password_login_enabled`.

`mvs_default_privacy` is the level applied when the uploader makes no choice. When `mvs_allow_user_privacy` is off, the privacy select is removed from the upload form and every upload takes the default.

### Display

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_grid_columns` | Grid Columns | integer | `3` |
| `mvs_items_per_page` | Items Per Page | integer | `12` |
| `mvs_thumbnail_size` | Thumbnail Quality | string | `medium` |
| `mvs_thumbnail_style` | Thumbnail Style | string | `original` |
| `mvs_large_image_size` | Large Image Size | integer | `1024` |
| `mvs_lightbox_image_source` | Lightbox Image Size | string | `original` |

`mvs_large_image_size` is the long-edge pixel width of the generated `large` variant. `mvs_lightbox_image_source` chooses which file the lightbox opens: `original` for full quality, or the `large` variant to save bandwidth on image-heavy pages.

### Social and messaging

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_dm_access` | Who Can Send DMs | string | `everyone` |
| `mvs_dm_min_age` | Minimum Account Age (days) | integer | `0` |
| `mvs_chat_panel_visibility` | Chat Panel Visibility | string | `everywhere` |
| `mvs_show_online_status` | Online Status Visibility | string | `everyone` |
| `mvs_comment_edit_window` | *(no settings field - filterable)* | integer | `900` (15 minutes) |

`mvs_dm_access` accepts `everyone`, `followers`, `mutual` or `nobody`. This is the only place the word "followers" appears as a setting value - it controls who may open a conversation with a member, and has nothing to do with media privacy.

`mvs_comment_edit_window` is the number of seconds after posting during which a member may still edit their own comment.

### Moderation and reporting

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_enable_reports` | Member Reporting | boolean | `true` |
| `mvs_report_auto_hide_threshold` | Auto-Hide Threshold | integer | `3` |
| `mvs_abuse_contact_email` | Abuse Contact Email | string | *(empty)* |
| `mvs_moderation_auto_action` | When AI Flags Content | string | *(empty)* |

`mvs_report_auto_hide_threshold` is the number of distinct reports after which a media item is hidden automatically pending review. Set `mvs_abuse_contact_email` if you want abuse notifications sent somewhere other than the site admin address.

### AI

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_ai_provider` | AI Provider | string | `openai` |
| `mvs_openai_api_key` | OpenAI API Key | string | *(empty)* |
| `mvs_openai_model` | OpenAI Model | string | `gpt-4o-mini` |
| `mvs_ai_auto_analyze` | Auto-Analyze Uploads | boolean | `false` |
| `mvs_ai_auto_tag` | Generate Tags | boolean | `true` |
| `mvs_ai_auto_apply_tags` | Auto-Apply Tags | boolean | `false` |
| `mvs_ai_auto_describe` | Generate Descriptions | boolean | `true` |
| `mvs_ai_auto_moderate` | Auto-Moderate Uploads | boolean | `false` |
| `mvs_ai_moderation_categories` | AI Flag Criteria | array | `nudity`, `violence`, `hate`, `self-harm`, `drugs`, `spam` |
| `mvs_ai_moderation_custom_terms` | Custom Flag Terms | string | *(empty)* |
| `mvs_ai_monthly_budget` | Monthly AI Budget ($) | string | *(empty)* |
| `mvs_ai_cost_per_call` | *(no settings field - filterable)* | number | `0.01` |

Note the pairing: `mvs_ai_auto_tag` decides whether tags are *generated*, and `mvs_ai_auto_apply_tags` decides whether they are *attached to the media item automatically* rather than suggested for review. Generating without applying is the safe starting configuration.

`mvs_ai_moderation_categories` falls back to the full category list when stored empty, so an empty value never silently disables flagging. `mvs_ai_monthly_budget` combined with `mvs_ai_cost_per_call` drives the spend estimate and the budget cutoff.

### Webhooks

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_webhooks` | Webhook Configuration | array | *(empty array)* |

See [Webhooks](webhooks.md) for the payload format and the event list.

## Pro options

Registered by `Admin\ProSettings` and `Admin\GamificationSettings`. All Pro options are inert unless WPMediaVerse Pro is active.

### Feature toggles

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_stories_enabled` | Stories | string | `0` |
| `mvs_connectors_enabled` | Enable Connectors | string | `0` |
| `mvs_streaks_enabled` | Enable Streaks | string | `0` |
| `mvs_watermark_enabled` | Enable Watermark | boolean | `false` |
| `mvs_autopilot_enabled` | Enable Autopilot | string | `0` |

Toggles stored as strings compare against `'1'`. When a feature is off its service is never constructed, so its REST routes are not registered and its scheduled actions do not run - which is why a disabled feature's endpoints return 404 rather than 403.

The gamification toggles `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled` and `mvs_boosts_enabled` follow the same pattern and are covered in [Gamification](../gamification/overview.md).

### AI providers

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_google_vision_key` | Google Cloud API Key | string | *(empty)* |
| `mvs_pro_anthropic_key` | Anthropic API Key | string | *(empty)* |
| `mvs_pro_anthropic_model` | Claude Model | string | `claude-haiku-4-5-20251001` |

### Gamification

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_battle_win_xp` | Battle Win Reward (XP) | integer | `100` |
| `mvs_pro_boost_cost_per_100` | Points per 100 Impressions | integer | `50` |
| `mvs_pro_boost_max_impressions` | Max Impressions per Boost | integer | `5000` |
| `mvs_pro_boost_expiry_days` | Boost Expiry (Days) | integer | `7` |
| `mvs_streak_freezes_enabled` | Allow Streak Freezes | string | `0` |
| `mvs_pro_streak_freeze_cost` | Freeze Cost (Points) | integer | `100` |

### Challenge autopilot

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_autopilot_day` | Day of Week | string | `monday` |
| `mvs_autopilot_hour` | Time | integer | `9` |
| `mvs_autopilot_entry_days` | Entry Period (Days) | integer | `7` |
| `mvs_autopilot_voting_days` | Voting Period (Days) | integer | `3` |
| `mvs_autopilot_max_entries` | Max Entries per User | integer | `1` |

Autopilot creates the next themed challenge from the Theme Library on the configured day and hour. `mvs_autopilot_entry_days` and `mvs_autopilot_voting_days` set the length of each phase, so a challenge's full cycle is the sum of the two.

### Connectors and layout

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_connector_flickr_app_key` | Flickr Plugin API Key | string | *(empty)* |
| `mvs_pro_connector_flickr_app_secret` | Flickr Plugin API Secret | string | *(empty)* |
| `mvs_pro_feed_layout` | Explore Page Layout | string | `grid` |

### Other

| Option key | Setting label | Type | Default |
|---|---|---|---|
| `mvs_pro_webhook_secret` | Webhook Secret | string | *(empty)* |
| `mvs_pro_settings` | *(container option)* | array | *(empty array)* |

`mvs_pro_settings` is a single array option holding assorted Pro values that have no dedicated key. Read it with `get_option( 'mvs_pro_settings', array() )` rather than assuming a shape.

## Storing credentials in `wp-config.php`

API keys and cloud credentials can be defined as constants instead of being stored in the database. When a constant is defined, the matching settings field renders locked and shows "Defined in wp-config.php", and the stored option is ignored.

This keeps secrets out of database dumps and out of staging copies. See [Cloud Storage](../pro-features/cloud-storage.md) for the Cloudflare R2, S3 and DigitalOcean Spaces constant names.

## Related

- [General Settings](general.md) - the General tab, explained in context
- [Display Settings](display.md)
- [Permissions](permissions.md)
- [AI & Moderation](ai-moderation.md)
- [Social & Messaging Settings](social.md)
- [Webhooks](webhooks.md)
- [Hooks & Filters Reference](../developer-guide/hooks-filters.md) - filters that override these values at runtime

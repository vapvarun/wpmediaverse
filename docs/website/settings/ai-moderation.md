# AI & Moderation Settings

Access these settings at **Media > Settings > AI & Moderation**.

[screenshot: AI and Moderation settings tab]

## AI Features Section

| Option | Default | Description |
|--------|---------|-------------|
| AI Provider | OpenAI (GPT-4 Vision) | The AI service used for image analysis and moderation. Free version: OpenAI only. Pro adds Google Vision and AWS Rekognition. |
| OpenAI API Key | (empty) | Your OpenAI API key. You can also define `MVS_OPENAI_API_KEY` in `wp-config.php` instead. |
| OpenAI Model | GPT-4o Mini | Model used for analysis calls. **GPT-4o Mini** is cheaper; **GPT-4o** provides higher quality results. |
| Auto-Analyze Uploads | Off | When enabled, each new upload is automatically analyzed for a description and suggested tags. |
| Auto-Apply Tags | Off | When enabled, AI-suggested tags are automatically assigned to the `mvs_tag` taxonomy on new uploads. Requires Auto-Analyze to be on. |
| Auto-Moderate Uploads | Off | When enabled, each new upload is checked for policy violations. The action taken depends on the **When AI Flags Content** setting below. |
| Monthly AI Budget ($) | 0 (unlimited) | Set a dollar cap on AI API costs per calendar month. When the budget is reached, AI calls stop until the next month. Set to 0 to disable budget limiting. |
| Estimated Cost per Call ($) | $0.01 | Used for budget tracking. Adjust based on your actual API pricing. |

## Setting the API Key via wp-config.php

```php
// wp-config.php
define( 'MVS_OPENAI_API_KEY', 'sk-your-key-here' );
```

When this constant is defined, the settings page field is disabled and shows a notice.

## Moderation Section

| Option | Default | Description |
|--------|---------|-------------|
| When AI Flags Content | Flag for review | What happens when AI detects a policy violation. Options: **Flag for review** (keeps media visible but adds it to the moderation queue), **Hide** (sets media to private), **Reject** (moves media to draft). |
| Auto-Hide Threshold | 3 | Number of user reports required to automatically hide a media item. The media is set to private and added to the moderation queue. Set to 0 to disable automatic hiding. |

## Moderation Queue

Administrators with the `moderate_mvs_media` capability can review flagged media at **Media > Moderation Queue**.

[screenshot: Moderation queue with pending media items and approve/reject actions]

The queue shows:
- Media flagged by AI
- Media that reached the auto-hide threshold from user reports
- Media manually flagged by moderators

## Log Viewer

The AI & moderation activity log is available at **Media > AI Logs**. It shows each AI call, the result, estimated cost, and any action taken.

[screenshot: AI log viewer showing a list of analysis results with cost column]

## Budget Alerts

When monthly AI spend reaches 80% of your budget, WPMediaVerse adds an admin notice. When the budget is fully consumed, AI calls are suspended and a warning appears on the settings page.

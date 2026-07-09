# AI Providers

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.



WPMediaVerse Pro adds Google Cloud Vision, AWS Rekognition, and Claude (Anthropic) as AI analysis providers alongside the built-in OpenAI Vision option. All four providers support auto-tagging and content moderation.

![AI and Moderation settings tab showing provider selector](../images/admin-settings-general.png)

## Supported Providers

| Provider | Auto-Tag | Content Moderation | Configuration Location |
|----------|----------|--------------------|----------------------|
| OpenAI Vision (free + Pro) | Yes | Yes | Media > Settings > AI & Moderation |
| Google Cloud Vision | Yes | Yes | Media > Settings > AI & Moderation > Google Vision |
| AWS Rekognition | Yes | Yes | Media > Settings > AI & Moderation > AWS Rekognition |
| Claude (Anthropic) | Yes | Yes | Media > Settings > AI & Moderation > Claude |

Only one provider is active at a time. Select it under **Media > Settings > AI & Moderation > Provider**.

---

## Google Cloud Vision

### Requirements

- A Google Cloud project with the Cloud Vision API enabled
- An API key or service account with `roles/cloudvision.user`

### Settings

| Option | Option Key | Description |
|--------|-----------|-------------|
| Vision API Key | `mvs_pro_google_vision_key` | Google Cloud API key |

![Google Vision settings section with API key field](../images/admin-settings-general.png)

### What Google Vision Returns

- **Labels:** Object and scene descriptions, mapped to `mvs_tag` terms when Auto-Apply Tags is on
- **Safe Search:** Likelihood scores for adult, violence, racy, medical, and spoof categories
- **Web Detection:** Public web entities that match the image (used as supplemental tags)

---

## AWS Rekognition

### Requirements

- AWS account with the Rekognition service enabled in your chosen region
- IAM user or role with `rekognition:DetectLabels` and `rekognition:DetectModerationLabels` permissions

### Settings

| Option | Option Key | Description |
|--------|-----------|-------------|
| Access Key ID | `mvs_pro_aws_access_key` | AWS IAM access key ID |
| Secret Access Key | `mvs_pro_aws_secret_key` | AWS IAM secret access key |
| Region | `mvs_pro_aws_region` | AWS region, e.g. `us-east-1` |

![AWS Rekognition settings section](../images/admin-settings-general.png)

### What Rekognition Returns

- **Labels:** Hierarchical object and scene labels with confidence scores, mapped to `mvs_tag` terms
- **Moderation Labels:** Content categories (nudity, violence, drugs, etc.) with confidence scores used by the moderation threshold settings

---

## Claude (Anthropic) **(New in 1.8.0)**

### Requirements

- An Anthropic account and API key with billing enabled

### Settings

| Option | Option Key | Description |
|--------|-----------|-------------|
| Anthropic API Key | `mvs_pro_anthropic_key` | Your Anthropic API key |
| Claude Model | `mvs_pro_anthropic_model` | Vision-capable model used for analysis, tagging, and moderation. Choices: **Claude Haiku 4.5** (fast & low cost, recommended, default), **Claude Sonnet 4.6** (stronger judgment), **Claude Opus 4.8** (most capable) |

![Claude settings section with API key and model fields](../images/admin-settings-general.png)

### What Claude Returns

- **Description + tags:** same natural-language description and comma-separated tag suggestions as the OpenAI path, mapped to `mvs_tag` terms when Auto-Apply Tags is on
- **Moderation:** flags content against the site's configured [AI Flag Criteria and Custom Flag Terms](../settings/ai-moderation.md#ai-flag-criteria-and-custom-flag-terms-new-in-180) - the same category checkboxes and custom-terms field the free plugin's OpenAI path uses

---

## Circuit Breaker

WPMediaVerse Pro implements a circuit breaker for all AI providers. If a provider records 5 consecutive failures (network timeout, invalid API key, rate limit), the circuit opens and no further API calls are made to that provider for a cooldown period of 1 hour. This prevents quota exhaustion during outages.

While the circuit is open, calls to that provider are skipped. The failure counter is stored in a transient that expires after the cooldown, and a successful call resets the counter immediately. There is no separate half-open/retry phase or manual reset command.

---

## Auto-Tagging Behaviour

All providers map their returned labels to `mvs_tag` taxonomy terms. Terms are created if they do not exist. Auto-tagging and the confidence/threshold controls that govern it are configured through the free plugin's AI settings (Auto-Apply Tags and the moderation thresholds under **Media > Settings > AI & Moderation**) - the Pro providers feed into that same pipeline rather than adding their own tag-confidence option keys.

---

## Content Moderation Thresholds

Each safety category has an independent threshold. When a category's confidence score meets or exceeds the threshold, the moderation action configured in **When AI Flags Content** is applied.

Configure thresholds at **Media > Settings > AI & Moderation > Moderation Thresholds**.

![Moderation threshold sliders for content categories](../images/admin-settings-general.png)

# AI Providers

> **Requires WPMediaVerse Pro** — This feature is available exclusively in the Pro version.


> **Requires WPMediaVerse Pro** — This feature is available exclusively in the Pro version.

WPMediaVerse Pro adds Google Cloud Vision and AWS Rekognition as AI analysis providers alongside the built-in OpenAI Vision option. All three providers support auto-tagging and content moderation.

![AI and Moderation settings tab showing provider selector](../images/admin-settings-general.png)

## Supported Providers

| Provider | Auto-Tag | Content Moderation | Configuration Location |
|----------|----------|--------------------|----------------------|
| OpenAI Vision (free + Pro) | Yes | Yes | Media > Settings > AI & Moderation |
| Google Cloud Vision | Yes | Yes | Media > Settings > AI & Moderation > Google Vision |
| AWS Rekognition | Yes | Yes | Media > Settings > AI & Moderation > AWS Rekognition |

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

## Circuit Breaker

WPMediaVerse Pro implements a circuit breaker for all AI providers. If a provider returns 5 consecutive errors (network timeout, invalid API key, rate limit), the circuit opens and no further API calls are made for 5 minutes. This prevents quota exhaustion during outages.

When the circuit is open:
- New uploads skip AI analysis and are marked with `_mvs_ai_status = skipped`
- The circuit half-opens after 5 minutes and retries a single request
- If the retry succeeds, the circuit closes and normal operation resumes
- If the retry fails, the circuit stays open for another 5 minutes

You can reset the circuit manually from WP Admin at **Media > Settings > AI & Moderation > Reset Circuit Breaker**, or via WP-CLI:

```bash
wp mvs ai reset-circuit
```

---

## Auto-Tagging Behaviour

All providers map their returned labels to `mvs_tag` taxonomy terms. Terms are created if they do not exist. Confidence thresholds control which labels are applied:

| Setting | Option Key | Default | Description |
|---------|-----------|---------|-------------|
| Min Tag Confidence | `mvs_pro_ai_tag_confidence` | `70` | Minimum provider confidence score (0–100) to apply a tag |
| Max Tags Per Item | `mvs_pro_ai_max_tags` | `10` | Cap on the number of tags applied per media item |

---

## Content Moderation Thresholds

Each safety category has an independent threshold. When a category's confidence score meets or exceeds the threshold, the moderation action configured in **When AI Flags Content** is applied.

Configure thresholds at **Media > Settings > AI & Moderation > Moderation Thresholds**.

![Moderation threshold sliders for content categories](../images/admin-settings-general.png)

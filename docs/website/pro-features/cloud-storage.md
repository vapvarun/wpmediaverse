# Cloud Storage

> **Requires WPMediaVerse Pro** — This feature is available exclusively in the Pro version.


> **Requires WPMediaVerse Pro** — This feature is available exclusively in the Pro version.

Stop storing media on your web server — offload every photo and video to Amazon S3 or BunnyCDN for faster delivery, lower server load, and global CDN performance.

## Why Use Cloud Storage

- Your WordPress server no longer stores or serves media files — reducing disk usage and bandwidth costs
- Files are served from edge locations closest to each visitor, so images load faster worldwide
- S3 and BunnyCDN both scale to millions of files without any WordPress configuration changes
- Private media gets signed URLs so only authorized users can access the actual file — even with cloud storage

## Setting Up Amazon S3

1. Log into your [AWS Console](https://console.aws.amazon.com/) and create an S3 bucket
2. Create an IAM user with the required permissions (see IAM Policy below) and save the access key and secret key
3. In WordPress, go to **Media > Settings > Storage**
4. Set **Storage Driver** to **Amazon S3**
5. Enter your bucket name, region, access key ID, and secret access key
6. If you use CloudFront or a custom domain, enter the hostname in the **CDN Domain** field
7. Click **Test Connection** — WPMediaVerse Pro uploads a small test file and reads it back to confirm everything works
8. Click **Save Settings** — all new uploads now go directly to S3

![S3 configuration fields in Storage settings](../images/admin-settings-storage.png)

## Setting Up BunnyCDN

1. Log into your [BunnyCDN dashboard](https://bunny.net/) and create a Storage Zone
2. Note your storage zone name, API key, region, and pull zone hostname
3. In WordPress, go to **Media > Settings > Storage**
4. Set **Storage Driver** to **BunnyCDN**
5. Enter your storage zone name, API key, region, and CDN hostname
6. Click **Test Connection** to verify
7. Click **Save Settings** — all new uploads now go to BunnyCDN

![Cloud Storage settings panel showing driver selector](../images/admin-settings-storage.png)

## Choosing a Storage Driver

Go to **Media > Settings > Storage** and set the **Storage Driver** option. The value is stored in the `mvs_storage_driver` option.

| Value | Driver |
|-------|--------|
| `local` | Default WordPress uploads directory (no Pro required) |
| `s3` | Amazon S3 |
| `bunny` | BunnyCDN |

Only one driver is active at a time. Switching drivers does not migrate existing files — previously uploaded files remain at their original URLs.

---

## Amazon S3

![S3 configuration fields in Storage settings](../images/admin-settings-storage.png)

### Settings

| Option | Option Key | Description |
|--------|-----------|-------------|
| S3 Bucket | `mvs_pro_s3_bucket` | The name of your S3 bucket |
| S3 Region | `mvs_pro_s3_region` | AWS region code, e.g. `us-east-1` |
| Access Key ID | `mvs_pro_s3_access_key` | Your AWS IAM access key ID |
| Secret Access Key | `mvs_pro_s3_secret_key` | Your AWS IAM secret access key |
| CDN Domain | `mvs_pro_s3_cdn_domain` | Optional CloudFront or custom domain for file URLs |

### Storing Credentials in wp-config.php

Instead of saving credentials to the database, define them as constants in `wp-config.php`:

```php
define( 'MVS_PRO_AWS_ACCESS_KEY', 'AKIAIOSFODNN7EXAMPLE' );
define( 'MVS_PRO_AWS_SECRET_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
```

When these constants are defined, WPMediaVerse Pro uses them instead of the database values. The admin fields show a placeholder indicating constants are in use.

### Required IAM Policy

Your IAM user needs at minimum:

```json
{
  "Effect": "Allow",
  "Action": [
    "s3:PutObject",
    "s3:GetObject",
    "s3:DeleteObject",
    "s3:ListBucket"
  ],
  "Resource": [
    "arn:aws:s3:::your-bucket-name",
    "arn:aws:s3:::your-bucket-name/*"
  ]
}
```

### CDN Domain

If you serve your bucket through CloudFront or a custom domain, enter the hostname (without trailing slash) in the **CDN Domain** field. WPMediaVerse Pro replaces the default S3 URL with this domain for all generated file URLs.

---

## BunnyCDN

![BunnyCDN configuration fields in Storage settings](../images/admin-settings-storage.png)

### Settings

| Option | Option Key | Description |
|--------|-----------|-------------|
| Storage Zone | `mvs_pro_bunny_zone` | Your BunnyCDN storage zone name |
| API Key | `mvs_pro_bunny_api_key` | Your BunnyCDN API key |
| Region | `mvs_pro_bunny_region` | Storage region: `de`, `ny`, `la`, `sg`, `syd` |
| CDN Hostname | `mvs_pro_bunny_cdn_hostname` | Your pull zone hostname, e.g. `media.yoursite.b-cdn.net` |

### Regions

| Value | Location |
|-------|----------|
| `de` | Falkenstein, Germany (default) |
| `ny` | New York, USA |
| `la` | Los Angeles, USA |
| `sg` | Singapore |
| `syd` | Sydney, Australia |

---

## Testing the Connection

After saving settings, click **Test Connection** in the Storage settings panel. WPMediaVerse Pro uploads a small test file, reads it back, then deletes it. The result (success or error message) appears inline without a page reload.

![Storage settings panel showing connection test result](../images/admin-settings-storage.png)

If the test fails, verify your credentials, bucket name, and that the IAM or API key has sufficient permissions.

---

## File Path Structure

Files are stored under the same path structure used for local uploads:

```
wpmediaverse/YYYY/MM/filename.ext
```

For S3 this becomes `s3://your-bucket/wpmediaverse/YYYY/MM/filename.ext`. For BunnyCDN it becomes a path within your storage zone.

## Signed URLs with Cloud Storage

When media privacy is not `public`, WPMediaVerse Pro generates signed URLs through the active cloud driver rather than the local file system. S3 presigned URLs use the AWS SDK. BunnyCDN signed URLs use token authentication configured on your pull zone.

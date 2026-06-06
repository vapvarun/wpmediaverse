# Cloud Storage

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.

Stop storing media on your web server - offload every photo and video to Amazon S3 or BunnyCDN for faster delivery, lower server load, and global CDN performance.

## Pluggable Storage Architecture

WPMediaVerse separates **where media records live** (always in your WordPress database) from **where files are stored** (local, S3, or BunnyCDN). Every upload goes through a `StorageDriverInterface` - a clean abstraction that means:

- **Switch drivers anytime** - Change from local to S3 in one setting. Existing files continue to serve from their original location; new uploads go to the new driver.
- **Build your own driver** - Developers can register custom drivers (Google Cloud Storage, DigitalOcean Spaces, Wasabi) by implementing the `StorageDriverInterface`. See [Custom Storage Drivers](../developer-guide/custom-storage-drivers.md).
- **Signed URLs for private media** - Cloud drivers generate time-limited signed URLs so private and members-only media stays protected even when served from a public CDN.
- **No lock-in** - Media metadata stays in your WordPress database regardless of storage driver. Switch providers without losing any data.

## Why Use Cloud Storage

- Your WordPress server no longer stores or serves media files - reducing disk usage and bandwidth costs
- Files are served from edge locations closest to each visitor, so images load faster worldwide
- S3 and BunnyCDN both scale to millions of files without any WordPress configuration changes
- Private media gets signed URLs so only authorized users can access the actual file - even with cloud storage

---

## How Media Serving Works in 1.4.0

### Location-based serving

Each media item is served from **where it is actually stored**, based on its storage location and privacy setting at the time of the request. The active storage driver setting only controls where new uploads go - it does not affect files that were uploaded previously.

This means:

- Switching the active driver (for example, from local to S3) does not break any existing media. Files already on S3 keep serving from S3. Files on local disk keep serving through the plugin's `/serve` route.
- Enabling a cloud integration for the first time does not affect older uploads - they continue working as before.
- Public media stored on cloud serves **directly from the CDN** (no WordPress request involved). Local media serves through the plugin's `/serve` proxy route.

### Private media stays local

Only **public** media is eligible for cloud storage. Media with any other privacy setting (members-only, friends-only, private, or group) is always stored on the local server disk. This applies to the original file, all thumbnails, and all generated image variants (WebP, AVIF).

There is effectively one storage location per media item at any time: either cloud (for public media) or local (for everything else).

### Per-request access check for private media

Every request to the `/serve` route for non-public media re-verifies the requesting user's view permission (`can_view`). A signed URL does not act as a transferable bearer token for private media. If the viewer no longer has access, the request returns 403 - even with a valid, unexpired signed URL. Public media uses bearer-style URLs (cacheable and shareable) because they carry no access restriction.

### Cloudflare R2 requires a public domain

If you use Cloudflare R2 and have **not** configured a public domain (r2.dev subdomain or custom domain) on your bucket, WPMediaVerse will not emit the raw `*.r2.cloudflarestorage.com` API URL. That endpoint is never publicly readable. Instead, the plugin falls back to serving the file from the local copy via `/serve`.

To enable true CDN serving from R2, configure a public domain for your bucket in the Cloudflare R2 dashboard (either the r2.dev subdomain or your own custom domain), then enter that hostname in the **CDN Domain** field in Storage settings.

### "Serve public cloud media directly" setting retired

The **Serve public cloud media directly** checkbox (`mvs_cloud_direct_public_urls`) has been removed from the settings UI in 1.4.0. Direct CDN serving for public cloud media is now automatic. You do not need to enable any toggle. The underlying option is retained in the database for back-compatibility, but it has no effect on serving behavior.

If you had this checkbox enabled before upgrading, no action is needed - behavior is the same or better.

---

## Setting Up Amazon S3

1. Log into your [AWS Console](https://console.aws.amazon.com/) and create an S3 bucket
2. Create an IAM user with the required permissions (see IAM Policy below) and save the access key and secret key
3. In WordPress, go to **Media > Settings > Storage**
4. Set **Storage Driver** to **Amazon S3**
5. Enter your bucket name, region, access key ID, and secret access key
6. If you use CloudFront or a custom domain, enter the hostname in the **CDN Domain** field
7. Click **Test Connection** - WPMediaVerse Pro uploads a small test file and reads it back to confirm everything works
8. Click **Save Settings** - all new uploads now go directly to S3

![S3 configuration fields in Storage settings](../images/admin-settings-storage.png)

## Setting Up BunnyCDN

1. Log into your [BunnyCDN dashboard](https://bunny.net/) and create a Storage Zone
2. Note your storage zone name, API key, region, and pull zone hostname
3. In WordPress, go to **Media > Settings > Storage**
4. Set **Storage Driver** to **BunnyCDN**
5. Enter your storage zone name, API key, region, and CDN hostname
6. Click **Test Connection** to verify
7. Click **Save Settings** - all new uploads now go to BunnyCDN

![Cloud Storage settings panel showing driver selector](../images/admin-settings-storage.png)

## Choosing a Storage Driver

Go to **Media > Settings > Storage** and set the **Storage Driver** option. The value is stored in the `mvs_storage_driver` option.

| Value | Driver |
|-------|--------|
| `local` | Default WordPress uploads directory (no Pro required) |
| `s3` | Amazon S3 |
| `bunnycdn` | BunnyCDN |
| `r2` | Cloudflare R2 |
| `dospaces` | DigitalOcean Spaces |

Only one driver is active at a time. Switching drivers does not migrate existing files - previously uploaded files remain at their original URLs and continue to serve from their original location.

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

When media privacy is not `public`, WPMediaVerse Pro generates signed URLs through the `/serve` proxy route. The proxy re-verifies view permission on every request - signed URLs for non-public media do not grant transferable access. S3 presigned URLs and BunnyCDN token authentication are used for migration and admin operations, not for end-user delivery of private media.

---

## Developer: Filtering Public Cloud URLs

The public-cloud serving behavior can be adjusted using three filters. Full parameter details are in the [Developer Guide: Hooks and Filters](../developer-guide/hooks-filters.md).

| Filter | What it controls |
|--------|-----------------|
| `mvs_serve_public_cloud_direct` | Return `false` to force all media back through the `/serve` proxy instead of emitting direct CDN URLs |
| `mvs_public_cloud_thumbnail_url` | Rewrite or replace the direct CDN URL for a public cloud-hosted thumbnail |
| `mvs_public_cloud_file_url` | Rewrite or replace the direct CDN URL for a public cloud-hosted original file |

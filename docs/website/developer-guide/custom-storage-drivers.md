# Custom Storage Drivers

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


WPMediaVerse uses a pluggable storage driver system. The free plugin ships with a **Local** driver (stores files in the WordPress uploads directory). WPMediaVerse Pro adds Amazon S3 and BunnyCDN drivers. You can implement your own driver by implementing the `StorageDriverInterface`.

## StorageDriverInterface

```php
namespace WPMediaVerse\Services;

interface StorageDriverInterface {

    /**
     * Store a file.
     *
     * @param string $source_path  Absolute local path of the temporary file.
     * @param string $dest_path    Relative destination path (e.g., "2025/03/photo.jpg").
     * @return bool True on success.
     */
    public function store( string $source_path, string $dest_path ): bool;

    /**
     * Delete a file.
     *
     * @param string $path Relative path.
     * @return bool True on success.
     */
    public function delete( string $path ): bool;

    /**
     * Get the public URL for a file.
     *
     * @param string $path Relative path.
     * @return string Full URL.
     */
    public function url( string $path ): string;

    /**
     * Check if a file exists.
     *
     * @param string $path Relative path.
     * @return bool
     */
    public function exists( string $path ): bool;

    /**
     * Get the absolute filesystem path for a stored file.
     *
     * @since 1.1.0
     *
     * @param string $path Relative path.
     * @return string Absolute file path.
     */
    public function get_full_path( string $path ): string;

    /**
     * Download a stored file to a local destination path.
     *
     * REQUIRED for two flows:
     *   1. The `wp mvs migrate-storage` CLI command — copies media between
     *      drivers without losing files.
     *   2. Thumbnail generation in cloud mode — images stored on S3/BunnyCDN
     *      must be pulled to a local temp file before `wp_get_image_editor()`
     *      can resize them.
     *
     * A local driver returns true immediately if `$path` and `$local_dest`
     * resolve to the same file (no-op). Cloud drivers stream the remote object
     * into `$local_dest`. Return false on any download failure (the caller is
     * responsible for retries / cleanup of partial writes).
     *
     * @since 1.2.2
     *
     * @param string $path       Relative path of the source file.
     * @param string $local_dest Absolute local filesystem path to write to.
     * @return bool True on success.
     */
    public function download( string $path, string $local_dest ): bool;
}
```

All six methods are required. A driver that does not implement every method will fail PHP's interface contract at load time.

## Implementing a Custom Driver

```php
use WPMediaVerse\Services\StorageDriverInterface;

class MyS3CompatibleDriver implements StorageDriverInterface {

    private string $bucket;
    private string $endpoint;

    public function __construct() {
        $this->bucket   = get_option( 'my_storage_bucket' );
        $this->endpoint = get_option( 'my_storage_endpoint' );
    }

    public function store( string $source_path, string $dest_path ): bool {
        // Upload $source_path to $this->bucket/$dest_path.
        // Return true on success.
    }

    public function delete( string $path ): bool {
        // Delete $this->bucket/$path.
    }

    public function url( string $path ): string {
        return $this->endpoint . '/' . $this->bucket . '/' . $path;
    }

    public function exists( string $path ): bool {
        // Check if object exists in bucket.
    }

    public function get_full_path( string $path ): string {
        // For remote drivers, this may return the URL or a temp local path.
        return $this->url( $path );
    }

    public function download( string $path, string $local_dest ): bool {
        // Stream $this->bucket/$path into the local file $local_dest.
        // Return true on success, false on any failure.
    }
}
```

## Registering Your Driver

`StorageService::get_driver()` resolves the active driver by passing the configured driver name through the **singular** `mvs_storage_driver` filter and expecting a driver **instance** back. The filter receives two arguments — the current driver (`null` until something supplies one) and the configured driver name — and your callback returns your driver instance only when the name matches your slug, otherwise it returns `$driver` unchanged:

```php
add_filter( 'mvs_storage_driver', function( $driver, string $name ) {
    return 'my_s3_compatible' === $name ? new MyS3CompatibleDriver() : $driver;
}, 10, 2 );
```

If no filter returns a `StorageDriverInterface` instance, `StorageService` falls back to the built-in `LocalDriver`.

This is exactly how WPMediaVerse Pro registers its own drivers — its callback `switch`es on `$name` and returns the matching driver (`s3`, `bunnycdn`, `r2`, `dospaces`):

```php
// Simplified from WPMediaVerse Pro.
add_filter( 'mvs_storage_driver', function( $driver, string $name ) {
    switch ( $name ) {
        case 's3':
            return new S3StorageDriver();
        case 'bunnycdn':
            return new BunnyCDNStorageDriver();
        default:
            return $driver;
    }
}, 10, 2 );
```

Then set the active driver to your slug so `StorageService` picks it:

```bash
wp option update mvs_storage_driver my_s3_compatible
```

(Or add your slug to the settings-page dropdown via a separate filter so site owners can switch to it from the admin UI.)

## Local Driver Reference

The built-in local driver stores files at:

```
{wp_upload_dir['basedir']}/wpmediaverse/YYYY/MM/filename.ext
```

Files are served from:

```
{wp_upload_dir['baseurl']}/wpmediaverse/YYYY/MM/filename.ext
```

`get_full_path()` returns the absolute filesystem path, which is used by services like `WatermarkService` and `AIService` that need to read the file from disk.

## Signed URLs and Private Delivery

Your driver does **not** generate signed URLs — that is `SignedUrlService`'s job. The flow is:

1. A read-side caller asks `Core\MediaUrl::thumb()` / `::file()` (or `SignedUrlService` directly) for a URL.
2. `SignedUrlService::generate()` / `::generate_thumbnail()` runs the privacy check (`PrivacyService::can_view()`), then either:
   - returns a signed `/serve` proxy URL (HMAC-signed query params: media ID, viewer user ID, expiry, signature) for gated/private media, **or**
   - returns a **direct** driver URL for public media on a cloud driver, so the browser hits the CDN edge instead of WordPress.
3. When the browser requests a `/serve` URL, `SignedUrlService::serve()` re-validates the signature and re-checks `can_view()` per request, then streams the bytes by reading the file from your driver (via `get_full_path()` / `download()`).

There is **no** `mvs_generate_signed_url` filter. The public extension points that actually exist let a cloud driver substitute its own CDN/presigned URL for the public-media direct path:

| Filter | Args | When it fires |
|--------|------|---------------|
| `mvs_serve_public_cloud_direct` | `(bool $enabled, int $media_id)` | Gate the "serve public cloud media directly" behavior on/off per media. |
| `mvs_public_cloud_thumbnail_url` | `(string $url, int $media_id, string $size)` | Final say on the public thumbnail URL for cloud-hosted media — return a presigned/CDN URL here. |
| `mvs_public_cloud_file_url` | `(string $url, int $media_id, string $context)` | Final say on the public full-file URL for cloud-hosted media. |

```php
// Substitute a presigned URL for your driver's public thumbnails.
add_filter( 'mvs_public_cloud_thumbnail_url', function( string $url, int $media_id, string $size ) {
    if ( 'my_s3_compatible' !== get_option( 'mvs_storage_driver' ) ) {
        return $url;
    }
    // Build the relative variant path and sign it with your SDK.
    $rel = get_post_meta( $media_id, 'thumb_' . $size . '_path', true );
    return $rel ? my_generate_presigned_url( $rel, 3600 ) : $url;
}, 10, 3 );
```

Private and restricted media is never eligible for the direct-cloud path — `StorageService::get_driver_for_privacy()` keeps non-public media on local disk, and `/serve` re-checks `can_view()` on every request. So these filters only affect **public** media.

## Viewer-Aware Full-File URLs (2.0.0)

`MediaRepositoryInterface::get_url_for_viewer()` is a driver-agnostic, viewer-aware way to get a URL for a media item's **full original file** (not a thumbnail) that is authorized for a specific viewer rather than the current logged-in user. It exists so callers that already have a `$viewer_id` (a REST controller resolving on behalf of a specific user, an email/notification job, an app backend) don't have to swap the global user or hand-roll a signed URL.

```php
namespace WPMediaVerse\Repository;

interface MediaRepositoryInterface {

    /**
     * Viewer-aware URL for the full original file.
     *
     * For public media on a cloud driver this returns the direct CDN URL (same
     * as the broadcast path); for local/non-public media it returns the gated
     * /serve URL. Returns '' when the viewer may not view the media.
     *
     * @since 2.0.0
     *
     * @param int      $media_id  Media ID.
     * @param int|null $viewer_id Viewer to authorize against. Null = current user.
     * @return string Signed URL when the viewer may view the media, else ''.
     */
    public function get_url_for_viewer( int $media_id, ?int $viewer_id = null ): string;
}
```

```php
$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

// Resolve the file URL as it would appear to a specific member (not the current request user).
$url = $repo->get_url_for_viewer( $media_id, $target_user_id );

if ( '' === $url ) {
    // $target_user_id is not authorized to view this media.
}
```

Like every other read-side URL helper in this plugin, do not hand-build the path or call `SignedUrlService` directly — `get_url_for_viewer()` runs the same privacy check and driver resolution as `Core\MediaUrl` (see [Template Overrides — Getting Media URLs](template-overrides.md#getting-media-urls-in-a-template-mediaurl)), just parameterized by an explicit viewer instead of the current user. Custom storage drivers do not need to implement anything extra for this to work — it composes `StorageService` + `SignedUrlService` the same way the rest of the read path does.

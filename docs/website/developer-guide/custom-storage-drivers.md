# Custom Storage Drivers

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
}
```

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
}
```

## Registering Your Driver

Use the `mvs_storage_drivers` filter to add your driver to the storage service:

```php
add_filter( 'mvs_storage_drivers', function( array $drivers ): array {
    $drivers['my_s3_compatible'] = new MyS3CompatibleDriver();
    return $drivers;
} );
```

Then set `mvs_storage_driver` to `my_s3_compatible` in the database (or add it to the settings page dropdown via a separate filter).

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

## Signed URLs

If your driver supports private file delivery, implement signed URL generation by integrating with the `SignedUrlService`. The `SignedUrlService` stores tokens in the database and validates them on the signed URL REST endpoint. For local storage, signed URLs append a `?token=` parameter that the plugin validates before serving the file.

For cloud drivers, you can generate native presigned URLs (e.g., S3 presigned URLs) and return them instead:

```php
add_filter( 'mvs_generate_signed_url', function( string $url, int $media_id, int $ttl ) {
    if ( 'my_s3_compatible' === get_option( 'mvs_storage_driver' ) ) {
        $path = get_post_meta( $media_id, '_mvs_file_path', true );
        return my_generate_presigned_url( $path, $ttl );
    }
    return $url;
}, 10, 3 );
```

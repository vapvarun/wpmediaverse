# WPMediaVerse Coding Standards

Target quality: WooCommerce / WordPress core level. Every rule here exists because we already saw what happens when it is ignored — see `Known Debt` in `CLAUDE.md`.

---

## Hard Rules

| # | Category | Rule | Limit | Enforcement |
|---|----------|------|-------|-------------|
| 1 | File size | One class per file, max 500 lines | 500 lines | Code review + PHPStan (future) |
| 2 | Method size | Keep methods focused and scannable | 50 lines | Code review |
| 3 | Database | Always use `$wpdb->prepare()` for interpolated values | No raw interpolation | WPCS `phpcs` |
| 4 | Admin HTML | Template files only — no inline `echo` in PHP classes | `templates/admin/` | Code review |
| 5 | Hooks | Prefix all custom hooks with `mvs_`, snake_case | `mvs_*` | Code review |
| 6 | REST | Extend `WP_REST_Controller`; every endpoint needs `get_item_schema()` and a `permission_callback` | Required | Code review |
| 7 | Security | Nonce + capability check on every write operation | Required | WP Plugin QA MCP |
| 8 | Escaping | Escape all output: `esc_html()`, `esc_attr()`, `esc_url()` | Required | WPCS `phpcs` |
| 9 | Sanitization | Sanitize all input: `sanitize_text_field()`, `absint()`, etc. | Required | WPCS `phpcs` |
| 10 | i18n | Wrap all user-facing strings; text domain `wpmediaverse` | Required | WPCS `phpcs` |
| 11 | Error handling | Return `WP_Error` or call `LoggerService`; no silent failures | Required | Code review |
| 12 | Dependencies | Resolve services from the container; no direct `new` in class bodies | Required | Code review |

---

## Anti-Patterns

### 1. God Class

**What it looks like**
```php
// includes/Services/MediaService.php — 1,200 lines
class MediaService {
    public function upload() { ... }
    public function delete() { ... }
    public function moderate() { ... }
    public function generateThumbnail() { ... }
    public function sendNotification() { ... }
    public function buildFeed() { ... }
    // ... 40 more methods
}
```

**Why it's bad**  
One class that owns everything is impossible to test, impossible to review in a PR, and forces every contributor to understand the whole system before changing one line. We already have this problem in `BuddyPressIntegration.php` (2,811 lines). The rule exists to stop it spreading.

**Correct approach**  
Split by responsibility. Each class does one thing.
```php
// includes/Services/UploadService.php   — handles file ingestion
// includes/Services/ModerationService.php — handles AI/manual moderation
// includes/Social/NotificationService.php — handles notifications
// includes/Services/StatsService.php   — handles aggregations
```
If you add a method and the file crosses 500 lines, extract first.

---

### 2. Inline SQL Without `prepare()`

**What it looks like**
```php
// Never do this.
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE user_id = $user_id"
);
```

**Why it's bad**  
Raw interpolation is an SQL injection vector. WPCS will flag it, CI will fail, and the WordPress Plugin Review team will reject the submission.

**Correct approach**
```php
$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE user_id = %d",
        $user_id
    )
);
```
Every value that can vary must go through `prepare()`. Use `%d` for integers, `%s` for strings, `%f` for floats.

---

### 3. Copy-Paste Logic

**What it looks like**
```php
// In AlbumController::create_item()
$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
update_post_meta( $post_id, '_mvs_privacy', sanitize_text_field( $request->get_param( 'privacy' ) ?? 'public' ) );
update_post_meta( $post_id, '_mvs_album_type', sanitize_text_field( $request->get_param( 'album_type' ) ?? 'default' ) );

// Same block duplicated in AlbumController::update_item() with minor variation
$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
update_post_meta( $post_id, '_mvs_privacy', sanitize_text_field( $request->get_param( 'privacy' ) ?? 'public' ) );
update_post_meta( $post_id, '_mvs_album_type', sanitize_text_field( $request->get_param( 'album_type' ) ?? 'default' ) );
```

**Why it's bad**  
When the logic changes (new field, different default), it changes in one place and not the other. The bug is invisible until a user reports inconsistent behaviour.

**Correct approach**  
Extract a private method that both callers use:
```php
private function save_album_meta( int $post_id, WP_REST_Request $request ): void {
    MediaMeta::set( $post_id, 'privacy', sanitize_text_field( $request->get_param( 'privacy' ) ?? 'public' ) );
    MediaMeta::set( $post_id, 'album_type', sanitize_text_field( $request->get_param( 'album_type' ) ?? 'default' ) );
}
```
If the same logic appears in two places, it belongs in a shared method or service.

---

### 4. Tight Coupling (Pro importing Free classes directly)

**What it looks like**
```php
// In wpmediaverse-pro/includes/Services/ProUploadService.php
use WPMediaVerse\Services\UploadService; // Direct import of Free class.

class ProUploadService extends UploadService {
    // ...
}
```

**Why it's bad**  
This creates a hard compile-time dependency from Pro onto Free's internal structure. If Free refactors `UploadService`, Pro breaks — often silently, with a PHP fatal only visible at runtime.

**Correct approach**  
Pro hooks into `mvs_loaded` and reads services from the container. It never imports Free namespaces.
```php
// In wpmediaverse-pro/wpmediaverse-pro.php
add_action( 'mvs_loaded', function ( \WPMediaVerse\Core\ServiceContainer $container ): void {
    $upload = $container->get( 'upload' ); // Resolved at runtime, not compile-time.
    $container->register( 'pro.upload', fn() => new ProUploadEnhancer( $upload ) );
} );
```

---

### 5. Silent Failure

**What it looks like**
```php
try {
    $this->storage->store( $file_path );
} catch ( \Exception $e ) {
    // Something went wrong, but nobody will ever know.
}
```

Or the `return false` variant:
```php
public function process( int $media_id ): bool {
    if ( ! $this->validate( $media_id ) ) {
        return false; // Caller has no idea why this failed.
    }
    // ...
}
```

**Why it's bad**  
Failures that are not logged cannot be diagnosed. Support tickets arrive with "it just doesn't work" and there is nothing to look at. The `return false` variant also forces callers to guess at reasons.

**Correct approach**  
Log errors with `LoggerService` and return `WP_Error` with a descriptive code:
```php
try {
    $this->storage->store( $file_path );
} catch ( \Exception $e ) {
    LoggerService::error( 'storage', $e->getMessage(), array( 'media_id' => $media_id ) );
    return new WP_Error( 'mvs_storage_failed', $e->getMessage(), array( 'status' => 500 ) );
}
```

---

## Good Patterns (From the Codebase)

### Service Container — Lazy Dependency Resolution

Services are registered as factories and resolved on first use. Nothing is instantiated unless it is needed.

**`includes/Core/ServiceContainer.php`**
```php
/**
 * Register a service factory.
 */
public function register( string $key, callable $factory ): void {
    $this->factories[ $key ] = $factory;
}

/**
 * Get a service instance (lazy-loaded, cached).
 *
 * @throws \InvalidArgumentException If service is not registered.
 */
public function get( string $key ) {
    if ( isset( $this->instances[ $key ] ) ) {
        return $this->instances[ $key ];
    }

    if ( ! isset( $this->factories[ $key ] ) ) {
        throw new \InvalidArgumentException(
            sprintf( 'Service "%s" is not registered.', esc_html( $key ) )
        );
    }

    $this->instances[ $key ] = call_user_func( $this->factories[ $key ], $this );

    return $this->instances[ $key ];
}
```

**`includes/Core/Plugin.php` — `register_services()`**

Dependencies are declared in the factory, so the container injects them automatically:
```php
self::$container->register(
    'upload',
    function ( ServiceContainer $c ) {
        return new UploadService( $c->get( 'storage' ) );
    }
);
```
`UploadService` receives `StorageService` through its constructor. There is no `new StorageService()` inside `UploadService`.

---

### REST Controller Pattern

Every controller extends `WP_REST_Controller`, declares its namespace and base route, and provides a `permission_callback` on every writable endpoint.

**`includes/REST/Controller/AlbumController.php`**
```php
class AlbumController extends WP_REST_Controller {

    protected $namespace = 'mvs/v1';
    protected $rest_base = 'albums';

    public function __construct( AlbumService $albums, PrivacyService $privacy ) {
        $this->albums  = $albums;
        $this->privacy = $privacy;
    }

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => '__return_true',
                    'args'                => $this->get_collection_params(),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'create_item_permissions_check' ),
                ),
            )
        );
    }
}
```

Key points:
- Public read routes use `'permission_callback' => '__return_true'`.
- All write routes point to a dedicated `*_permissions_check()` method.
- Input is sanitized immediately: `sanitize_text_field( $request->get_param( 'title' ) ?? '' )`.
- The method returns `WP_Error` on any validation failure before doing any DB work.

---

### LoggerService — Hook-Driven Automatic Logging

`LoggerService::register_hooks()` is called once at boot. After that, any code that fires a documented hook gets structured logging for free — no logging calls scattered through business logic.

**`includes/Services/LoggerService.php`**
```php
public static function register_hooks(): void {
    // Media upload success.
    add_action(
        'mvs_media_uploaded',
        function ( int $media_id, array $file_data ): void {
            self::info(
                'upload',
                sprintf( 'Media uploaded: %s (%s)', $file_data['media_type'] ?? '', size_format( $file_data['file_size'] ?? 0 ) ),
                array(
                    'media_id'   => $media_id,
                    'file_type'  => $file_data['file_type'] ?? '',
                    'file_size'  => $file_data['file_size'] ?? 0,
                    'media_type' => $file_data['media_type'] ?? '',
                )
            );
        },
        10,
        2
    );

    // AI moderation flag.
    add_action(
        'mvs_media_flagged',
        function ( int $media_id, array $result ): void {
            self::warning(
                'moderation',
                'AI flagged media content',
                array(
                    'media_id' => $media_id,
                    'flags'    => $result['flags'] ?? array(),
                    'provider' => $result['provider'] ?? '',
                )
            );
        },
        10,
        2
    );
}
```

When you add a new hook (`do_action( 'mvs_*', ... )`), add the corresponding listener here. Do not call `LoggerService::info()` inline at the call site — fire the hook and let the logger pick it up.

---

### N+1 Prevention — Bulk Fetch Then Map

Loading related data in a loop produces one query per row (N+1). The pattern used throughout this codebase is: fetch all rows first, then fetch all related data in one query, then map in memory.

**`includes/Social/CommentService.php` — reply tree without N+1**
```php
// Fetch ALL replies for this media in one query, then build tree in memory.
$all_replies = array();
if ( $top_level ) {
    $replies_raw = get_comments(
        array(
            'post_id'        => $media_id,
            'type'           => self::COMMENT_TYPE,
            'status'         => 'approve',
            'parent__not_in' => array( 0 ),
            'orderby'        => 'comment_date_gmt',
            'order'          => 'ASC',
            'number'         => 0, // All replies.
        )
    );
    foreach ( $replies_raw as $reply ) {
        $parent_id = (int) $reply->comment_parent;
        if ( ! isset( $all_replies[ $parent_id ] ) ) {
            $all_replies[ $parent_id ] = array();
        }
        $all_replies[ $parent_id ][] = $reply;
    }
}

foreach ( $top_level as $comment ) {
    $result[] = $this->format_comment_with_replies( $comment, $all_replies );
}
```

**`includes/Social/NotificationService.php` — priming WP object cache**
```php
// Prime caches in bulk to avoid N+1 queries.
$actor_ids = array();
$media_ids = array();
foreach ( $rows as $row ) {
    $actor_ids[] = (int) $row->actor_id;
    if ( $row->media_id ) {
        $media_ids[] = (int) $row->media_id;
    }
}
if ( $actor_ids ) {
    // Pre-load all actor user objects in one query.
    new \WP_User_Query(
        array(
            'include' => array_unique( $actor_ids ),
            'fields'  => 'all',
        )
    );
}
if ( $media_ids ) {
    _prime_post_caches( array_unique( $media_ids ), false, false );
}
```

If you are iterating a result set and calling `get_user_by()`, `get_post()`, or similar inside the loop — stop. Collect the IDs, prime the cache, then iterate.

# WPMediaVerse Extension Guide

Reference for extending WPMediaVerse from an external plugin or theme without modifying core files.

---

## 1. Hooking into Plugin Lifecycle

WPMediaVerse exposes two lifecycle hooks that fire after full initialization. Hook into these instead of `plugins_loaded` to guarantee the service container and all internal services are ready.

**Free plugin**

`mvs_loaded` fires at the end of `Plugin::init()` and passes the `ServiceContainer` instance as its only argument.

```php
add_action( 'mvs_loaded', function( \WPMediaVerse\Core\ServiceContainer $container ) {
    // Your extension code here.
    // $container->get( 'storage' ) → StorageService
    // $container->get( 'reactions' ) → ReactionService
    // etc.
} );
```

**Pro plugin**

`mvs_pro_loaded` fires after the Pro extension bootstraps on top of Free. Use this hook in add-ons that require Pro features.

```php
add_action( 'mvs_pro_loaded', function() {
    // Safe to depend on Pro services here.
} );
```

> **Rule:** Never `use WPMediaVerse\...` classes directly in external code. Always go through hooks and the service container so your code survives core refactors.

---

## 2. Adding a Storage Driver

A storage driver handles the physical storage of uploaded files. Custom drivers let you integrate any cloud provider or on-premises object store.

### Interface contract

Your driver must implement four methods:

| Method | Signature | Description |
|--------|-----------|-------------|
| `upload` | `upload( string $local_path, string $remote_key ): string` | Store the file; return the canonical URL. |
| `delete` | `delete( string $remote_key ): bool` | Remove the file. |
| `get_url` | `get_url( string $remote_key ): string` | Return the public (or signed) URL. |
| `exists` | `exists( string $remote_key ): bool` | Return `true` if the file exists. |

### Registering via `mvs_storage_driver`

`StorageService` resolves the active driver through the `mvs_storage_driver` filter. Return your driver instance when the `$driver_name` matches your slug.

```php
add_filter( 'mvs_storage_driver', function( $driver, string $driver_name ) {
    if ( 'my-s3-driver' !== $driver_name ) {
        return $driver; // Pass through - not our driver.
    }
    return new MyS3Driver();
}, 10, 2 );
```

Register the driver slug in the admin by adding it to the Storage settings dropdown via `mvs_settings_sections` or a direct settings field, then set the `mvs_storage_driver_name` option to `my-s3-driver`.

---

## 3. Adding an AI Provider

AI providers power automatic moderation, content analysis, and tagging. You can add a provider alongside (or instead of) the built-in OpenAI integration.

### Interface contract

Your provider must implement three methods:

| Method | Signature | Description |
|--------|-----------|-------------|
| `analyze` | `analyze( int $media_id, string $file_path ): array` | Return analysis result array. |
| `moderate` | `moderate( int $media_id, string $file_path ): array` | Return moderation flags array. |
| `tag` | `tag( int $media_id, string $file_path ): array` | Return suggested tag strings. |

### Registering via `mvs_ai_providers`

`AIService` fires `mvs_ai_providers` and passes itself as the argument. Call `register_provider()` on the service.

```php
add_action( 'mvs_ai_providers', function( \WPMediaVerse\Services\AIService $service ) {
    $service->register_provider( 'my-vision-api', new MyVisionProvider() );
} );
```

Set the active provider slug in the AI Moderation settings panel (WPMediaVerse → Settings → AI Moderation → Provider).

---

## 4. Adding Custom REST Endpoints

All WPMediaVerse REST routes live under the `mvs/v1` namespace. External controllers must use the same namespace for consistency and to share the rate-limiter middleware.

### Controller skeleton

```php
namespace MyPlugin\REST;

class MyMediaExtController extends \WP_REST_Controller {

    protected $namespace = 'mvs/v1';
    protected $rest_base = 'my-extension';

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'get_items_permissions_check' ),
                    'args'                => $this->get_collection_params(),
                ),
                'schema' => array( $this, 'get_item_schema' ),
            )
        );
    }

    public function get_items_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        return current_user_can( 'read' );
    }

    public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        // Your logic here.
        return rest_ensure_response( array() );
    }

    public function get_item_schema(): array {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'my_extension',
            'type'       => 'object',
            'properties' => array(),
        );
    }
}

// Register on rest_api_init - never earlier.
add_action( 'rest_api_init', function() {
    ( new \MyPlugin\REST\MyMediaExtController() )->register_routes();
} );
```

> **Security:** Never set `permission_callback` to `__return_true` on endpoints that write data. Always check `current_user_can()` with an appropriate capability (`manage_mvs_media`, `moderate_mvs_media`, or a custom one).

---

## 5. Extending Admin UI

### Moderation tabs - `mvs_moderation_tabs`

The Moderation Queue page (`Admin\ModerationQueue`) renders its tabs through `apply_filters( 'mvs_moderation_tabs', $tabs )`. Each tab is an associative array with `id`, `label`, and `callback` keys.

```php
add_filter( 'mvs_moderation_tabs', function( array $tabs ): array {
    $tabs[] = array(
        'id'       => 'my-reports',
        'label'    => __( 'My Reports', 'my-plugin' ),
        'callback' => 'my_plugin_render_reports_tab',
    );
    return $tabs;
} );

function my_plugin_render_reports_tab(): void {
    echo '<div class="mvs-tab-panel">';
    esc_html_e( 'Custom report content.', 'my-plugin' );
    echo '</div>';
}
```

### Stats tabs - `mvs_stats_tabs`

The Stats page (`Admin\StatsPage`) uses the same pattern.

```php
add_filter( 'mvs_stats_tabs', function( array $tabs ): array {
    $tabs[] = array(
        'id'       => 'my-analytics',
        'label'    => __( 'My Analytics', 'my-plugin' ),
        'callback' => 'my_plugin_render_analytics_tab',
    );
    return $tabs;
} );
```

---

## 6. Frontend Hooks

Use these action hooks to inject HTML into WPMediaVerse frontend templates without overriding template files.

### `mvs_before_explore_grid`

Fires just before the media grid renders on the Explore page (`templates/explore.php`).

```php
add_action( 'mvs_before_explore_grid', function(): void {
    echo '<div class="my-explore-banner">';
    esc_html_e( 'Welcome to the gallery!', 'my-plugin' );
    echo '</div>';
} );
```

### `mvs_dashboard_tabs`

Fires inside the Dashboard tab bar (`templates/partials/dashboard-content.php`). Output a `<button>` or `<li>` element that references your panel ID.

```php
add_action( 'mvs_dashboard_tabs', function(): void {
    echo '<button class="mvs-tab-btn" data-tab="my-panel">'
        . esc_html__( 'My Tab', 'my-plugin' )
        . '</button>';
} );
```

### `mvs_dashboard_panels`

Fires after all default Dashboard panels. Output your panel `<div>` here; it will be hidden/shown by the tab switcher.

```php
add_action( 'mvs_dashboard_panels', function(): void {
    echo '<div id="mvs-panel-my-panel" class="mvs-tab-panel" hidden>';
    esc_html_e( 'My custom dashboard panel content.', 'my-plugin' );
    echo '</div>';
} );
```

---

## 7. Activity & Notification Hooks

### Registering custom activity types - `mvs_activity_types`

`ActivityService` resolves allowed activity types through `apply_filters( 'mvs_activity_types', self::TYPES )`. Add your own type slug to extend the feed.

```php
add_filter( 'mvs_activity_types', function( array $types ): array {
    $types[] = 'my_custom_event';
    return $types;
} );
```

Once registered, you can insert activity rows via `ActivityService::record()` using your type slug.

### Reacting to social events

Hook into these actions to run side-effects (points, notifications, webhooks, etc.) when users interact with media.

**`mvs_reaction_added`** - fires when a reaction is successfully added.

```php
add_action( 'mvs_reaction_added', function( int $media_id, int $user_id, string $reaction_type ): void {
    // Award points, log analytics, etc.
    my_plugin_award_points( $user_id, 'reaction', $media_id );
}, 10, 3 );
```

**`mvs_favorite_toggled`** - fires when a media item is favorited or un-favorited.

```php
add_action( 'mvs_favorite_toggled', function( int $media_id, int $user_id, string $action ): void {
    // $action is 'added' or 'removed'.
    if ( 'added' === $action ) {
        my_plugin_log_favorite( $user_id, $media_id );
    }
}, 10, 3 );
```

**Other useful social hooks**

| Hook | Type | Args | Fired when |
|------|------|------|------------|
| `mvs_comment_created` | action | `$media_id, $user_id, $comment_id, $content, $source` | A comment is posted |
| `mvs_user_followed` | action | `$follower_id, $following_id` | A follow relationship is created |
| `mvs_media_shared` | action | `$media_id, $user_id, $platform` | Media is shared to a platform |
| `mvs_media_uploaded` | action | `$media_id` | A new media item is saved |
| `mvs_moderation_changed` | action | `$media_id, $status, $old_status, $user_id` | Moderation status changes |

## Template overrides (child themes)

Every member-facing template resolves **theme-first**, so a child (or parent) theme can override any of them by placing a file under a `wpmediaverse/` directory in the theme:

```
wp-content/themes/<child>/wpmediaverse/<template>.php
```

Resolution order (via `\WPMediaVerse\Core\TemplateLoader::locate()`):
1. `wp-content/themes/<child>/wpmediaverse/<template>` (child theme)
2. `wp-content/themes/<parent>/wpmediaverse/<template>` (parent theme)
3. the plugin's own `templates/<template>` (fallback)

### Overridable full-page templates

`explore.php`, `media-single.php`, `album.php`, `collection.php`, `cpt-archive.php`, `profile-edit.php`, `404.php`, `messages.php`.

Pro adds: the Compete pages (`battles.php`, `challenges.php`, `tournaments.php`, `compete-hub.php`) and, for each active layout, `explore.php` / `user-profile.php`. **A theme override wins even when a Pro layout is active** — e.g. `wpmediaverse/explore.php` replaces the Instagram-layout feed.

### Partials

Partials loaded through the loader use the `partials` sub-path, e.g. override the global frame at `wpmediaverse/partials/shared-ui-frame.php`.

### Filters

- `mvs_locate_template( $path, $name, $subdir )` — final say on the resolved template path.
- `mvs_template_variables( $args, $name )` — mutate the variables passed to `TemplateLoader::get_template()`.
- `mvs_layout_template_map( $map, $layout )` (Pro) — remap which layout file a template name resolves to.

> Structural wrappers (`partials/router-region-open.php` / `router-region-close.php`) and `templates/admin/*` are intentionally **not** theme-overridable.

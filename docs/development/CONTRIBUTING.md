# WPMediaVerse - Contributor Guide

This document covers seven common development tasks. Each guide is self-contained: follow it top-to-bottom without reading anything else.

**Before starting:** Read `CLAUDE.md` for the module map, coding rules, and known tech-debt files. Run `composer install && npm install` if you have not already.

---

## Table of Contents

1. [How to Add a New Feature (Free)](#1-how-to-add-a-new-feature-free)
2. [How to Add a REST Endpoint](#2-how-to-add-a-rest-endpoint)
3. [How to Fix a Bug](#3-how-to-fix-a-bug)
4. [How to Add a Competition Type (Pro)](#4-how-to-add-a-competition-type-pro)
5. [How to Add a Storage Driver (Pro)](#5-how-to-add-a-storage-driver-pro)
6. [How to Add an AI Provider (Pro)](#6-how-to-add-an-ai-provider-pro)
7. [How to Add a Migration Importer (Pro)](#7-how-to-add-a-migration-importer-pro)

---

## 1. How to Add a New Feature (Free)

Follow these eight steps in order. Each step references the actual file path you will create or modify.

### Step 1 - Create the service class

Place business logic in `includes/Services/`. The file **must stay under 500 lines**; if your feature is larger, split it into two classes.

```
includes/Services/YourFeatureService.php
```

Namespace and basic structure:

```php
<?php
namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

class YourFeatureService {

    public function __construct( /* inject dependencies */ ) {}

    public function do_something(): void {
        // Implementation - max 50 lines per method.
        // Use $wpdb->prepare() for every query.
        // Log failures via LoggerService, never return false silently.
    }
}
```

### Step 2 - Register in the service container

Open `includes/Core/Plugin.php` and find the `register_services()` method. Add your service alongside the existing registrations - **do not add logic to this file, only registration calls**:

```php
self::$container->register(
    'your_feature',
    function () {
        $dep = self::$container->get( 'storage' ); // inject existing services as needed
        return new YourFeatureService( $dep );
    }
);
```

The container is resolved lazily, so this line costs nothing unless your service is actually used.

### Step 3 - Create the REST controller

Place it in `includes/REST/Controller/`:

```
includes/REST/Controller/YourFeatureController.php
```

Every controller must extend `WP_REST_Controller` and set the shared namespace:

```php
<?php
namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Response;
use WP_Error;
use WPMediaVerse\Services\YourFeatureService;

class YourFeatureController extends WP_REST_Controller {

    protected $namespace = 'mvs/v1';
    protected $rest_base = 'your-feature';

    private YourFeatureService $service;

    public function __construct( YourFeatureService $service ) {
        $this->service = $service;
    }

    public function register_routes(): void {
        // See Guide 2 for the full pattern.
    }

    public function get_item_schema(): array {
        // Required - define your response shape here.
    }
}
```

### Step 4 - Register routes via `rest_api_init`

Still in `includes/Core/Plugin.php`, find the `register_rest_routes()` method and add your controller. Look at how `MediaController` is wired there and follow the same pattern:

```php
$controller = new YourFeatureController(
    self::$container->get( 'your_feature' )
);
add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
```

### Step 5 - Add the admin UI

Create your admin page class in `includes/Admin/`:

```
includes/Admin/YourFeaturePage.php
```

**Do not echo HTML inside the PHP class.** Render it from a template:

```php
public function render(): void {
    // Prepare + escape everything here, then hand off to the template.
    // Admin templates are deliberately NOT theme-overridable, so require them
    // directly rather than through TemplateLoader (which resolves theme-first).
    require MVS_PLUGIN_DIR . 'templates/admin/your-feature.php';
}
```

(`TemplateLoader::get_template( $name, $args )` is the loader for *frontend* templates under `templates/`, which themes may override.)

Create the matching template:

```
templates/admin/your-feature.php
```

Register the admin page in `includes/Core/Plugin.php` inside `register_services()` and resolve it via the container in the `is_admin()` branch of `init()`, following the same pattern as `admin.overview`, `admin.stats`, etc.

### Step 6 - Add hooks with the `mvs_` prefix

All custom actions and filters must use the `mvs_` prefix in snake_case, consistent with the rest of the codebase:

```php
// Firing an action
do_action( 'mvs_your_feature_completed', $item_id, $user_id );

// Applying a filter
$value = apply_filters( 'mvs_your_feature_output', $value, $context );
```

Add PHPDoc blocks above every `do_action` / `apply_filters` call describing the parameters.

### Step 7 - Write a unit test

Create a test file in `tests/unit/`:

```
tests/unit/YourFeatureServiceTest.php
```

Use the existing tests (`tests/unit/ReactionServiceTest.php`, `tests/unit/FavoriteServiceTest.php`) as templates. Run the full suite before opening a PR:

```bash
./vendor/bin/phpunit
```

New code must not reduce coverage - write at least one test for every public method.

### Step 8 - Update CLAUDE.md and the manifest

Open `CLAUDE.md` at the plugin root and add your class to the Module Map table. Update `audit/manifests/manifest.json` (and the relevant detail file) with a targeted delta for any hook, REST route, setting, table, or CLI subcommand you added - never by committing generator output; see the manifest-refresh note at the top of `CLAUDE.md`.

Do **not** add a changelog entry to `CLAUDE.md`. It describes what the plugin is right now; release history goes in `readme.txt` only.

---

## 2. How to Add a REST Endpoint

This guide covers adding a single endpoint inside an existing or new controller. The REST namespace for all routes is `mvs/v1`.

### Step 1 - Create (or open) the controller

If adding to an existing resource (e.g., media), open the relevant file in `includes/REST/Controller/`. For a new resource, create a controller following the structure in Guide 1, Step 3.

The controller must extend `WP_REST_Controller`. Set:

```php
protected $namespace = 'mvs/v1';
protected $rest_base = 'your-resource'; // becomes /wp-json/mvs/v1/your-resource
```

### Step 2 - Define `register_routes()` with methods and callbacks

```php
public function register_routes(): void {
    register_rest_route(
        $this->namespace,
        '/' . $this->rest_base,
        array(
            array(
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
                'args'                => $this->get_endpoint_args_for_item_schema( true ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        )
    );

    register_rest_route(
        $this->namespace,
        '/' . $this->rest_base . '/(?P<id>[\d]+)',
        array(
            array(
                'methods'             => \WP_REST_Server::READABLE, // GET
                'callback'            => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'get_item_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'absint',
                        'required'          => true,
                    ),
                ),
            ),
            'schema' => array( $this, 'get_public_item_schema' ),
        )
    );
}
```

### Step 3 - Add a schema via `get_item_schema()`

Every controller must declare a schema. This is used by `get_endpoint_args_for_item_schema()` and by REST API discovery clients:

```php
public function get_item_schema(): array {
    return array(
        '$schema'    => 'http://json-schema.org/draft-04/schema#',
        'title'      => 'your_resource',
        'type'       => 'object',
        'properties' => array(
            'id'    => array( 'type' => 'integer', 'readonly' => true ),
            'title' => array( 'type' => 'string' ),
        ),
    );
}
```

### Step 4 - Sanitize all args via `sanitize_callback`

Never trust raw request input. For every argument in your route definition, supply a `sanitize_callback`:

```php
'title' => array(
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
    'validate_callback' => 'rest_validate_request_arg',
),
```

For integers use `absint`; for URLs use `esc_url_raw`; for HTML use `wp_kses_post`.

### Step 5 - Return `WP_Error` for failures, `WP_REST_Response` for success

```php
public function create_item( \WP_REST_Request $request ) {
    $result = $this->service->do_something( $request->get_param( 'title' ) );

    if ( is_wp_error( $result ) ) {
        return $result; // WP_Error is automatically converted to a JSON error response.
    }

    return new \WP_REST_Response( $this->prepare_item_for_response( $result, $request ), 201 );
}

public function create_item_permissions_check( \WP_REST_Request $request ) {
    if ( ! is_user_logged_in() ) {
        return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'wpmediaverse' ), array( 'status' => 401 ) );
    }
    return current_user_can( 'upload_files' );
}
```

### Step 6 - Register the controller in `Plugin.php`

Find `register_rest_routes()` in `includes/Core/Plugin.php` and wire your controller using the same pattern as `MediaController` (around line 986):

```php
$controller = new \WPMediaVerse\REST\Controller\YourFeatureController(
    self::$container->get( 'your_feature' )
);
add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
```

Test with `curl` or the WordPress REST API console:

```bash
curl -X POST https://your-site.local/wp-json/mvs/v1/your-resource \
  -H "Content-Type: application/json" \
  -d '{"title":"test"}'
```

---

## 3. How to Fix a Bug

### Step 1 - Reproduce and identify the module

Locate the failing behavior and map it to the correct module using the Module Map in `CLAUDE.md`. Common entry points:

- REST errors → `includes/REST/Controller/`
- Upload failures → `includes/Services/UploadService.php` or `includes/Services/StorageService.php`
- Admin UI issues → `includes/Admin/` + `templates/admin/` (or `templates/partials/`)
- Social interactions → `includes/Social/`
- BuddyPress-specific → `includes/Integrations/BuddyPress/` (the old 2,811-line `BuddyPressIntegration.php` was split into focused classes: `BuddyPressManager`, `ActivitySyncIntegration`, `ActivityContentIntegration`, `ProfileTabIntegration`, `GroupTabIntegration`, `NotificationIntegration`, `ActivityFormIntegration`, plus `BaseBPTabIntegration` / `MediaDisplayHelper`)

Enable `WP_DEBUG` and `WP_DEBUG_LOG` locally. Check `includes/Services/LoggerService.php` log output in `wp-content/debug.log`.

### Step 2 - Check existing tests

Before writing anything, run:

```bash
./vendor/bin/phpunit --filter YourModuleTest
```

Review `tests/unit/RESTApiTest.php`, `tests/unit/ReactionServiceTest.php`, etc., to understand what is already covered and to avoid duplicating setup work.

### Step 3 - Write a failing test first

Add a test case to the appropriate file in `tests/unit/` that demonstrates the bug. Commit this test before the fix so the failure is documented:

```php
public function test_it_should_not_return_null_when_media_id_is_valid(): void {
    $result = $this->service->get_something( 42 );
    $this->assertNotNull( $result );
}
```

Run `./vendor/bin/phpunit` and confirm the new test fails.

### Step 4 - Fix the issue

Make the targeted change in the source file. Keep the diff minimal - only change what is needed to fix the reported behavior. Do not reformat unrelated code or add features in the same commit.

**Do not add lines to files in the Known Debt table in `CLAUDE.md`** - currently `includes/Admin/Settings/SettingsRegistrar.php`, `includes/Services/UploadService.php`, and `MediaController::replace_file`. Extract code out of them first if the fix requires touching them. `CLAUDE.md` is the live list; check it rather than this paragraph. Note that `MessagingService.php`, `Plugin.php`, `MediaController.php` and `MessagingController.php` are explicitly listed there as "large but NOT debt" - edit them normally.

### Step 5 - Run phpcs, phpstan, tests, and the activation smoke test

All three static checks must pass before the commit:

```bash
composer run phpcs      # WordPress coding standards
composer run phpstan    # static analysis (baseline: phpstan-baseline.neon)
./vendor/bin/phpunit    # unit tests
```

Fix any new violations introduced by your change. Do not suppress errors with `// phpcs:ignore` unless you add a comment explaining why it is unavoidable.

**Then run the activation smoke test** - green static checks do not prove the plugin activates. See `docs/development/LOCAL_TESTING.md` §1 for the 30-second WP-CLI check that catches missing classes and fatal activation hooks before they reach QA.

If your change touches `wpmediaverse.php`, `libs/`, or a class loaded at boot, also run the fresh-clone fatal check in `docs/development/LOCAL_TESTING.md` §2 - this is the check that would have caught Pro #9788342062 on 2026-04-15.

### Step 6 - Run the local-CI gate

Before pushing, run the full gate. It is what the pre-push hook runs, and it covers the plugin-specific rules that WPCS cannot see:

```bash
composer ci                # full pipeline
composer ci:no-journeys    # everything except browser journeys
composer ci:quick          # PHP lint + coding rules only
```

Address any findings before opening a PR.

---

## 4. How to Add a Competition Type (Pro)

Pro code lives in the separate `wpmediaverse-pro` repository. It hooks into the free plugin exclusively via `mvs_loaded` and the `ServiceContainer` - Pro code must **never** `use` any `WPMediaVerse\` namespace class directly.

### Step 1 - Create the service

```
includes/YourCompetition/YourCompetitionService.php
```

```php
<?php
namespace WPMediaVersePro\YourCompetition;

defined( 'ABSPATH' ) || exit;

class YourCompetitionService {

    private $container; // ServiceContainer from free plugin

    public function __construct( $container ) {
        $this->container = $container;
    }
}
```

### Step 2 - Create the REST controller

```
includes/YourCompetition/YourCompetitionController.php
```

Follow the same `WP_REST_Controller` pattern from Guide 2. Use namespace `mvs/v1` and a unique `rest_base` (e.g., `competitions/tournament`).

### Step 3 - Create the admin manager

```
includes/Admin/YourCompetitionManager.php
```

This class handles admin menu registration, settings rendering, and any metaboxes specific to the competition type. Render HTML from template files under `templates/admin/` - no inline HTML in PHP classes.

### Step 4 - Add a settings toggle in `ProSettings.php`

Open `includes/Admin/ProSettings.php` in the Pro plugin and add a boolean option for enabling/disabling the new competition type:

```php
register_setting( 'mvs_pro_settings', 'mvs_enable_your_competition', array(
    'type'    => 'boolean',
    'default' => false,
) );
```

### Step 5 - Register in `Plugin.php::init()` with a toggle check

In the Pro plugin's bootstrap (`Plugin.php` or equivalent `init()` method), gate the service behind the toggle and hook into `mvs_loaded`:

```php
add_action( 'mvs_loaded', function( $container ) {
    if ( ! get_option( 'mvs_enable_your_competition', false ) ) {
        return;
    }
    $service    = new \WPMediaVersePro\YourCompetition\YourCompetitionService( $container );
    $controller = new \WPMediaVersePro\YourCompetition\YourCompetitionController( $service );
    add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
} );
```

### Step 6 - Add gamification hooks

Fire event actions so gamification engines and other consumers can award points. Use the `mvs_` prefix and follow the naming and argument order of the competition hooks Pro already fires (documented in `docs/development/INTEGRATION-EVENT-HOOKS.md`):

```php
do_action( 'mvs_challenge_entry_submitted', $challenge_id, $user_id, $media_id );
do_action( 'mvs_challenge_winner_named',    $challenge_id, $winner_user_id, $rank );
do_action( 'mvs_battle_resolved',           $battle_id, $winner_id, $loser_id );
```

Add your new hooks to `INTEGRATION-EVENT-HOOKS.md` and to `audit/manifests/manifest.hooks.json` in the same PR. WPMediaVerse owns no integration manifest for any specific consumer - consumers hook the actions.

### Step 7 - Add to `CompetitionsDashboard` stat cards

Locate the competitions dashboard component in the Pro plugin and add a stat card for the new type. Follow the existing card markup pattern for visual consistency with `wbcom-modern-admin` standards.

---

## 5. How to Add a Storage Driver (Pro)

WPMediaVerse stores files through `includes/Services/StorageService.php`, which resolves the active driver via the `mvs_storage_driver` filter. The built-in driver is `LocalDriver` (`includes/Services/LocalDriver.php`).

### Step 1 - Create the driver class

```
includes/Storage/YourDriver.php    (in the Pro plugin)
```

Implement `StorageDriverInterface` (`includes/Services/StorageDriverInterface.php` in the free plugin):

```php
<?php
namespace WPMediaVersePro\Storage;

defined( 'ABSPATH' ) || exit;

class YourDriver implements \WPMediaVerse\Services\StorageDriverInterface {

    public function store( string $source_path, string $dest_path ): bool {
        // Upload $source_path to remote storage, store at $dest_path.
    }

    public function delete( string $path ): bool {
        // Delete file at $path from remote storage.
    }

    public function url( string $path ): string {
        // Return the public CDN/signed URL for $path.
    }

    public function exists( string $path ): bool {
        // Return true if the file exists in remote storage.
    }

    public function get_full_path( string $path ): string {
        // Return an absolute local path, or empty string if not applicable.
    }

    public function download( string $path, string $local_dest ): bool {
        // Pull the remote file down to $local_dest. Used by migrate-storage.
    }
}
```

### Step 2 - Implement all six interface methods

The interface (`includes/Services/StorageDriverInterface.php`) defines six methods: `store`, `delete`, `url`, `exists`, `get_full_path`, and `download` (added 1.2.2 for `wp mvs migrate-storage`). PHP will throw a fatal error if any is missing. Review the `LocalDriver` implementation for reference on expected behavior.

### Step 3 - Register via the `mvs_storage_driver` filter

Hook in during `mvs_loaded` to guarantee the free plugin's container is ready:

```php
add_action( 'mvs_loaded', function() {
    add_filter( 'mvs_storage_driver', function( $driver, string $driver_name ) {
        if ( 'yourdriver' === $driver_name ) {
            return new \WPMediaVersePro\Storage\YourDriver(
                get_option( 'mvs_pro_yourdriver_api_key', '' )
            );
        }
        return $driver;
    }, 10, 2 );
} );
```

`StorageService` reads the `mvs_storage_driver` option for the driver name and then applies this filter to resolve the instance (see `includes/Services/StorageService.php`, line 39).

### Step 4 - Add a settings section in `ProSettings.php`

Register the API key, bucket, and region options your driver requires. Add a settings section that is only shown when the admin has selected your driver name in the driver dropdown. Follow the conditional display pattern already used in Free's `includes/Admin/Settings/` classes for the `mvs_storage_driver` select control.

### Step 5 - Test with `wp mvs` CLI commands

Use the existing WP-CLI commands to verify connectivity:

```bash
wp mvs stats          # confirms the plugin is running
wp mvs migrate        # verify schema is intact after activation
```

Once media exists on the old driver, `wp mvs migrate-storage --from=<old> --to=<new> --dry-run` exercises `download()` / `store()` / `exists()` / `delete()` end to end. The full runbook for a new driver is `docs/development/STORAGE-DRIVER-VERIFICATION.md`.

---

## 6. How to Add an AI Provider (Pro)

AI analysis, tagging, and moderation are routed through `includes/Services/AIService.php`, which collects providers via the `mvs_ai_providers` action (fired in `includes/Core/Plugin.php`, inside the `ai` container factory).

### Step 1 - Create the provider class

```
includes/AI/YourProvider.php    (in the Pro plugin)
```

Implement `AIProviderInterface` (`includes/Services/AIProviderInterface.php` in the free plugin):

```php
<?php
namespace WPMediaVersePro\AI;

defined( 'ABSPATH' ) || exit;

class YourProvider implements \WPMediaVerse\Services\AIProviderInterface {

    private string $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = $api_key;
    }

    public function analyze_image( string $image_url ): ?array {
        // Call your AI API. Return ['description' => '...', 'confidence' => 0.95].
        // Return null on failure - AIService handles null gracefully.
    }

    public function generate_tags( string $image_url, string $description = '' ): array {
        // Return an array of tag strings.
    }

    public function moderate_content( string $image_url ): array {
        // Return ['safe' => true, 'flags' => [], 'confidence' => 0.99].
    }

    public function is_available(): bool {
        return ! empty( $this->api_key );
    }

    public function get_id(): string {
        return 'yourprovider'; // Must be unique across all registered providers.
    }
}
```

### Step 2 - Implement all five interface methods

The interface requires `analyze_image`, `generate_tags`, `moderate_content`, `is_available`, and `get_id`. See `includes/Services/OpenAIProvider.php` for the canonical implementation to follow. `analyze_image` should return `null` (not `WP_Error`) on API failure so the caller can degrade gracefully.

### Step 3 - Register via the `mvs_ai_providers` action

```php
add_action( 'mvs_loaded', function() {
    add_action( 'mvs_ai_providers', function( $ai_service ) {
        $provider = new \WPMediaVersePro\AI\YourProvider(
            get_option( 'mvs_pro_yourprovider_api_key', '' )
        );
        $ai_service->register_provider( $provider );
    } );
} );
```

`AIService` fires `mvs_ai_providers` and passes itself as the argument (see the `ai` container factory in `includes/Core/Plugin.php`). Call `register_provider( $provider )` on the service instance - it takes the instance only and keys it by `get_id()`.

### Step 4 - Add API key settings

In `ProSettings.php`, register a text field for the API key:

```php
register_setting( 'mvs_pro_settings', 'mvs_pro_yourprovider_api_key', array(
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
) );
```

Add a settings row that only appears when your provider is selected as the active AI provider. Display a live "Test Connection" button that calls `is_available()` via a lightweight admin AJAX handler.

---

## 7. How to Add a Migration Importer (Pro)

Migration importers run as WP-CLI batch commands and pull content from external platforms into `mvs_media_index`.

### Step 1 - Create a CLI command class

```
includes/CLI/YourPlatformImportCommand.php    (in the Pro plugin)
```

```php
<?php
namespace WPMediaVersePro\CLI;

defined( 'ABSPATH' ) || exit;

class YourPlatformImportCommand {

    /**
     * Import media from YourPlatform.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would be imported without writing to the database.
     *
     * [--batch-size=<n>]
     * : Number of items to process per batch. Default: 50.
     *
     * ## EXAMPLES
     *
     *     wp mvs import-yourplatform --batch-size=100
     *
     * @subcommand import-yourplatform
     */
    public function __invoke( array $args, array $assoc_args ): void {
        $dry_run    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $batch_size = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 50 );
        $total      = $this->get_source_count();
        $progress   = \WP_CLI\Utils\make_progress_bar( "Importing from YourPlatform", $total );

        $offset = 0;
        while ( $offset < $total ) {
            $batch = $this->fetch_batch( $offset, $batch_size );
            foreach ( $batch as $item ) {
                if ( ! $dry_run ) {
                    $this->import_item( $item );
                }
                $progress->tick();
            }
            $offset += $batch_size;
        }

        $progress->finish();
        \WP_CLI::success( "Import complete. {$total} items processed." );
    }

    private function get_source_count(): int {
        // Query the external platform API for the total number of items.
        // Return the integer count so the progress bar is accurate.
    }

    private function fetch_batch( int $offset, int $batch_size ): array {
        // Fetch $batch_size items starting at $offset from the external API.
        // Return a plain array of normalized associative arrays.
    }

    private function import_item( array $item ): void {
        // Write one item to the mvs_media_index table.
        // Use $wpdb->prepare() - no raw SQL interpolation.
        // Use LoggerService to record failures, not die() or WP_CLI::error().
    }
}
```

### Step 2 - Implement the three core methods

| Method | Responsibility |
|---|---|
| `get_source_count()` | Returns the total item count from the remote API. Used to initialize the progress bar and calculate offsets. |
| `fetch_batch( $offset, $batch_size )` | Returns a normalized array of items. Normalize to a consistent shape here so `import_item` is simple. |
| `import_item( $item )` | Writes one record to `{$wpdb->prefix}mvs_media_index` and any associated rows in `mvs_media_meta`. Handle duplicates with `INSERT ... ON DUPLICATE KEY UPDATE` or a pre-check. |

### Step 3 - Register the WP-CLI command

Hook into `mvs_loaded` so the free plugin's container is available:

```php
add_action( 'mvs_loaded', function( $container ) {
    if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
        return;
    }
    \WP_CLI::add_command(
        'mvs import-yourplatform',
        new \WPMediaVersePro\CLI\YourPlatformImportCommand( $container )
    );
} );
```

Verify the command appears in the list:

```bash
wp mvs --help
```

### Step 4 - Add a platform card in `MigrationPage.php`

Locate `MigrationPage.php` in the Pro plugin's admin directory and add a card for your platform following the existing card pattern. The card should show:

- Platform name and logo
- "Import" button that launches the WP-CLI command via an admin AJAX kick-off (or links to the CLI documentation)
- Last-run timestamp and item count from a stored option

### Step 5 - Add an AJAX detection handler

Register an admin AJAX action so the migration page can show real-time progress or trigger a background import:

```php
add_action( 'wp_ajax_mvs_import_yourplatform', array( $this, 'handle_ajax_import' ) );
```

The handler should:
1. Verify a nonce with `check_ajax_referer( 'mvs_import_yourplatform' )`.
2. Check capability with `current_user_can( 'manage_options' )`.
3. Kick off the import as an Action Scheduler job or respond with the current import status.
4. Return a `wp_send_json_success()` / `wp_send_json_error()` response - never output raw HTML.

---

## General Rules

- **500-line file limit.** Split before adding lines to any file already near the limit.
- **No silent failures.** Use `WP_Error` for REST, `LoggerService` for service-layer failures.
- **Prepared queries always.** `$wpdb->prepare()` on every query with user-supplied input.
- **`mvs_` prefix on all hooks.** Actions and filters use `mvs_snake_case`.
- **Pro boundary.** Pro code hooks into `mvs_loaded` - it never `use`s free-plugin classes directly.
- **Templates for HTML.** All admin HTML lives in `templates/admin/`. No inline `echo` in PHP classes.
- **i18n required.** Wrap every user-facing string: `__()`, `esc_html__()`, `esc_attr__()` with text domain `wpmediaverse`.
- **Tests required.** Every new service method needs at least one PHPUnit test in `tests/unit/`.

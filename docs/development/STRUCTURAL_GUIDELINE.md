# WPMediaVerse Structural Guideline

> Where each kind of file lives, what shape it has, and the seams it must respect.
> Companion to `docs/architecture/ARCHITECTURE.md` (lifecycle + schema),
> `CODING_STANDARDS.md` (style), and
> `docs/architecture/architecture-contract.md` (the 11 Free ⇄ Pro behavioral
> invariants). This file answers the structural question: **"I'm
> adding a new feature - where does each piece go?"**

> **Skill reference**: the generic Wbcom-wide layered architecture lives at
> `wp-plugin-development/references/layered-architecture.md` (seven layers,
> per-file shape cheat sheets, anti-patterns, flowchart). This document is
> WPMediaVerse's **plugin-specific instance** of that guidance - it adds the
> concrete service-container keys, table names, and incident-driven
> anti-patterns that motivated each rule.

---

## 1. The seven layers

Every piece of code in the plugin belongs to exactly one of these layers.
Putting it in the wrong layer is a structural bug regardless of whether
the code is correct in isolation.

```
┌───────────────────────────────────────────────────────────────────────┐
│ 1. Bootstrap        wpmediaverse.php, Core/Plugin.php                 │
│                     Loads constants, vendor autoload, fires init.     │
├───────────────────────────────────────────────────────────────────────┤
│ 2. Container        Core/ServiceContainer.php + register_services()   │
│                     Lazy factories. No business logic here.           │
├───────────────────────────────────────────────────────────────────────┤
│ 3. Repository       Repository/MediaRepository{,Interface}.php        │
│                     Custom-table SQL ONLY. No HTTP, no rendering.     │
├───────────────────────────────────────────────────────────────────────┤
│ 4. Services         Services/*Service.php   (one capability each)     │
│   + Social/         Social/*Service.php                               │
│   + Integrations/   Integrations/<Platform>/*.php                     │
│                     Business logic. Depends on Repository + other     │
│                     services. Returns structured data - never HTTP    │
│                     responses, never echoed HTML.                     │
├───────────────────────────────────────────────────────────────────────┤
│ 5. Surface adapters REST/Controller/*Controller.php                   │
│   (HTTP/CLI/blocks) CLI/Commands.php                                  │
│                     Blocks/BlockRegistrar.php + src/blocks/*/render.php│
│                     Shortcodes/Shortcodes.php                         │
│                     Thin glue. Validates input → calls a service →    │
│                     formats the response. NO business logic.          │
├───────────────────────────────────────────────────────────────────────┤
│ 6. Templates        templates/*.php  +  templates/partials/*.php      │
│                     Presentation only. Reads via TemplateHelpers.     │
│                     Zero direct DB queries.                           │
├───────────────────────────────────────────────────────────────────────┤
│ 7. Admin UI         Admin/*Page.php + Admin/Settings/*.php            │
│                     Menu pages, settings forms, list tables. Submits  │
│                     to admin_post_* OR REST. No `wp_ajax_*` (Rule A). │
└───────────────────────────────────────────────────────────────────────┘
```

**Layer dependency rule**: a higher layer may depend on layers below it,
never above. Repository (3) does not know about REST controllers (5).
Services (4) do not echo HTML. Templates (6) do not run SQL.

---

## 2. Where each kind of file goes

| You're adding… | Lives at | Shape |
|---|---|---|
| A new database table | `Core/Migrator.php` `migrate_to_N` method | Pure schema; no business logic. Bumps `CURRENT_VERSION`. |
| A read/write of media data | `Repository/MediaRepository.php` (instance method) | One method = one query family. Returns scalars/arrays, never HTML. Add to `MediaRepositoryInterface`. |
| A piece of business logic for a capability | `Services/<Capability>Service.php` (one class per capability) | DI via constructor. Container key registered in `Plugin::register_services`. Public API methods return structured arrays/scalars. |
| A REST endpoint | `REST/Controller/<Capability>Controller.php` | Extends `WP_REST_Controller`. `register_routes()` declares paths; per-route `permission_callback` is mandatory; handler is ≤30 lines and delegates to a service. |
| A WP-CLI command | `CLI/Commands.php` (one method per command) | Same shape as REST handler - thin, delegates to a service. |
| A Gutenberg block | `src/blocks/<slug>/{block.json, render.php, edit.js, view.js}` | `render.php` SSR-first, calls a service or `TemplateHelpers`. No JS-loaded shells. |
| A shortcode | `Shortcodes/Shortcodes.php` (one method per shortcode) | Wraps the same renderer the block uses (Rule C). |
| A frontend template | `templates/<slug>.php` or `templates/partials/<slug>.php` | Reads via `Plugin::container()->get('template_helpers')`. Zero direct `MediaRepository` SQL. Theme-overridable. |
| An admin menu page | `Admin/<Name>Page.php` (one class per page) | Class implements `register_menu()` + `render_page()`. Forms submit to REST or `admin_post_*`. |
| Background work | `Services/<Capability>Service.php` schedules via Action Scheduler | Cron callback registers in `Plugin::init`; handler in the service. |
| A one-off install/seed/cleanup script | `Services/<Name>Service.php` exposing a public method that returns a structured result array | NOT an include-and-exit `*.php` at plugin root. The bootstrap script entry (if any) is a 5-line shim that calls the service. |
| Hooks fired by Free for Pro | Documented in `audit/manifests/manifest.hooks.json` | Free fires `do_action('mvs_…')` with a stable signature. Pro listens. Free MUST NOT call Pro classes (contract Invariant 1). |

---

## 3. File-shape cheat sheets

### REST Controller (Layer 5)

```php
final class FooController extends WP_REST_Controller {
    public function __construct(private FooService $foo, private PrivacyService $privacy) {}

    public function register_routes(): void {
        register_rest_route( 'mvs/v1', '/foo/(?P<id>[\d]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'do_thing' ],
            'permission_callback' => [ $this, 'permissions' ],   // ← mandatory
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
    }

    public function permissions( WP_REST_Request $req ): bool|WP_Error { /* … */ }

    public function do_thing( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $result = $this->foo->thing( (int) $req['id'] );          // ← single service call
        return rest_ensure_response( $result );                   // ← thin wrapper
    }
}
```

**A REST controller MUST NOT**: open transactions, run business logic,
emit HTML, call `wp_send_json_*()`, include another script that does any
of those.

### Service (Layer 4)

```php
final class FooService {
    public function __construct(private MediaRepositoryInterface $repo) {}

    /**
     * Returns a STRUCTURED result. Never echoes, never wp_die()s.
     *
     * @return array{success: bool, data: array, message?: string}
     */
    public function thing( int $id ): array {
        if ( ! $this->repo->exists( $id ) ) {
            return [ 'success' => false, 'message' => 'not found' ];
        }
        // … business logic …
        return [ 'success' => true, 'data' => [ … ] ];
    }
}
```

**A service MUST NOT**: parse `$_REQUEST`, output HTML, call
`wp_send_json_*()`, depend on REST/admin context. Same service runs from
WP-CLI, REST, admin_post, and unit tests.

### One-off install/seed/cleanup script

**Wrong shape (today's `seed-demo-data.php`):**
```php
<?php
defined( 'ABSPATH' ) || exit;
// … well over a thousand lines of inline imperative code …
if ( wp_doing_ajax() ) {
    wp_send_json_success( [ 'message' => $msg ] );   // ← can't be reused from REST/CLI
}
```

**Right shape:**
```php
// Services/DemoSeederService.php
final class DemoSeederService {
    public function seed(): array {
        // … pure logic …
        return [ 'success' => true, 'data' => [ 'imported' => 50 ] ];
    }
    public function cleanup(): array { /* … */ }
}

// REST/Controller/AdminController.php - thin
public function import_demo_data(): WP_REST_Response {
    return rest_ensure_response( $this->seeder->seed() );
}

// CLI/Commands.php - thin
public function seed( $args, $assoc_args ): void {
    WP_CLI::log( wp_json_encode( $this->seeder->seed() ) );
}
```

The 5-line bootstrap entry at plugin root (if any) is just:
```php
// seed-demo-data.php
require __DIR__ . '/wpmediaverse.php';
( new \WPMediaVerse\Services\DemoSeederService( /* deps */ ) )->seed();
```

---

## 4. Naming + namespace conventions

| Path | Namespace |
|---|---|
| `includes/Core/<Foo>.php`              | `WPMediaVerse\Core\<Foo>` |
| `includes/Repository/<Foo>.php`        | `WPMediaVerse\Repository\<Foo>` |
| `includes/Services/<Foo>Service.php`   | `WPMediaVerse\Services\<Foo>Service` |
| `includes/Social/<Foo>Service.php`     | `WPMediaVerse\Social\<Foo>Service` |
| `includes/Integrations/<P>/<Foo>.php`  | `WPMediaVerse\Integrations\<Platform>\<Foo>` |
| `includes/REST/Controller/<Foo>Controller.php` | `WPMediaVerse\REST\Controller\<Foo>Controller` |
| `includes/Admin/<Foo>Page.php`         | `WPMediaVerse\Admin\<Foo>Page` |
| `includes/Blocks/<Foo>.php`            | `WPMediaVerse\Blocks\<Foo>` |
| `includes/CLI/<Foo>.php`               | `WPMediaVerse\CLI\<Foo>` |

**Class-name suffixes**: `Service`, `Controller`, `Page`, `Repository`,
`Migrator`, `Registrar`, `Manager`. The suffix tells you what layer the
class belongs to at a glance.

---

## 5. Cross-plugin seams (Free ⇄ Pro)

Authoritative: `docs/architecture/architecture-contract.md`, Part A - the 11
invariants (enforced by Pro's `bin/architecture-checks.sh`). Structural summary:

- **Free→Pro**: Free fires `do_action('mvs_*')` hooks at stable points.
  Free never imports `WPMediaVersePro\…`.
- **Pro→Free**: Pro resolves Free services via
  `Plugin::container()->get('media_repository')` and
  `Plugin::container()->get('template_helpers')`. Pro never imports
  Free concrete classes (CI guard: Pro `bin/coding-rules-check.sh`
  Rule 3). Interface-only imports allowed when Pro IMPLEMENTS the
  interface (`StorageDriverInterface`, `AIProviderInterface`).
- **Settings**: Pro reads `mvs_*` options through Free service typed
  accessors (contract Invariant 4) - never raw `get_option()` for
  Free-owned options.
- **DB writes**: Pro writes Free tables only through `MediaRepository`
  (contract Invariant 6).

---

## 6. Adding new behavior - flowchart

```
        New behavior request
                │
                ▼
   ┌──────────────────────────┐
   │ Does it touch the DB?    │
   └────┬───────────────┬─────┘
        │ yes           │ no
        ▼               ▼
  Repository/      ┌─────────────────────────────┐
  *Interface       │ Is it stateful business     │
  + impl method    │ logic (ranking, scoring,    │
                   │ orchestration)?             │
                   └────┬───────────────┬────────┘
                        │ yes           │ no
                        ▼               ▼
                  Services/*Service  ┌─────────────────────┐
                                     │ Is it a data        │
                                     │ formatter (URL,     │
                                     │ thumb, label)?      │
                                     └────┬────────────────┘
                                          ▼
                                     TemplateHelpers
                                     (instance method)

   Need an external surface (HTTP/CLI/block/shortcode)?
                │
                ▼
        Add a thin adapter in
        REST/Controller, CLI/Commands,
        Blocks/, or Shortcodes/ that
        delegates to the service.
```

---

## 7. Anti-patterns to refuse on review

1. **Including a plugin-root `*.php` from a controller** - it bypasses
   layering and prevents reuse from WP-CLI / unit tests.
2. **`wp_send_json_*()` outside a REST handler or `admin-ajax`-only
   handler** - services must return data, not emit it.
3. **`MediaRepository` SQL from a template** - templates read via
   `TemplateHelpers`. Repository is the only SQL layer.
4. **A class named `*Service` with public methods that proxy to ≥2
   other `*Service` classes** - that's a facade (Rule B violation).
5. **`use WPMediaVerse\<ConcreteClass>;` in Pro source** - interfaces
   only. CI guard rejects this.
6. **`add_action('wp_ajax_*', …)` for new code without an allowlist
   entry** - Rule A's motivation (REST nonces, schema validation,
   rate limiting, single auth model for headless/decoupled clients)
   is real, but doesn't apply to admin-only one-click utilities
   triggered manually by a logged-in admin (connection testers,
   "dismiss this welcome banner", chunked admin migration tools,
   demo-data import/cleanup). Migrate handlers where REST adds
   structural value; **allowlist** admin-only manual triggers with
   a documented carve-out comment next to the `add_action` line.
   The CI guard tightens to forbid `admin-ajax` everywhere except
   the documented allowlist (mirrors Pro's `__return_true` REST
   callback allowlist pattern). Phase 6 demonstrated this - Free
   migrated `mvs_dismiss_welcome` (1-line update_user_meta) and
   allowlisted `mvs_import_demo_data` + `mvs_cleanup_demo_data`
   (admin manual triggers - refactoring the whole seeder for zero
   structural payoff is over-engineering). Those two are the only
   `wp_ajax_mvs_*` actions the plugin registers.
7. **`current_user_can('mvs/…')` for plugin-defined abilities** -
   route through `MediaCapabilities` / Pro's Permission_Engine.
8. **A new top-level directory** that doesn't match the seven layers
   - propose it in a PR description, not in the diff.

---

## 8. When this guideline is wrong

This file describes the *current* architecture. If a real-world feature
demands a structure outside it, update **this file first** in the same
PR that introduces the new shape - never silently. The guideline is a
living document; review evolves it.

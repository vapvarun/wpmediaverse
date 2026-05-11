# Naming Rules

> **Rule (standing):** A name is a contract. Every class, function, hook, and CSS selector in this plugin tells a future developer what it does and where it belongs — or it lies. Names that lie are worse than no names, because they encode bad assumptions as fact.
>
> **Why this rule exists:** In 2026-04 we found `.mvs-bp-upload-wrap`, `.mvs-bp-upload-preview`, `.mvs-bp-upload-thumb` used both inside BP integrations AND inside `templates/album.php` (a non-BP single-album view). The `-bp-` prefix was a lie. The class name suggested "BP only"; the actual usage was "BP and album view." Anyone inheriting the code has to read the emitters to find out, because the name can't be trusted.

---

## 1. The "names don't lie" principle

If a name implies a scope, the usage must match. When the implication and the usage diverge, pick one:

1. **Rename** to match actual usage (preferred when usage is stable and the old name is wrong)
2. **Narrow usage** to match the name (preferred when usage is a leak the name was warning us about)

Never leave the mismatch in place. "Everyone knows `.mvs-bp-*` is used outside BP too" is tribal knowledge — it evaporates the moment the current maintainers rotate off.

### The current known violator

`.mvs-bp-upload-wrap` / `.mvs-bp-upload-preview` / `.mvs-bp-upload-thumb` / `.mvs-bp-upload-thumb-name` / `.mvs-bp-upload-status` are used in `ProfileTabIntegration`, `GroupTabIntegration`, AND `templates/album.php`. The correct rename is `.mvs-inline-upload-*` — neutral about BP vs. non-BP. Deferred from the 2026-04 migration because it requires touching PHP + JS + CSS in one commit; planned separately.

---

## 2. CSS class names

### Prefix

All plugin-owned CSS classes start with `.mvs-`. No exceptions.

### Scope prefix (if applicable)

If a class is used in exactly one scope, the next segment names that scope:

- `.mvs-bp-X` — only used on BuddyPress surfaces
- `.mvs-admin-X` — only used in wp-admin
- `.mvs-msg-X` — only in DM / messaging surfaces (currently `.mvs-message-*` mostly)
- `.mvs-block-X` — only inside a specific Gutenberg block

If a class is used in ≥2 scopes, omit the scope prefix. `.mvs-inline-upload-X` is better than `.mvs-bp-upload-X` when the "upload" pattern is reused in non-BP contexts.

### Component / variant / state structure

Use BEM-style segmentation when useful:

- `.mvs-card` — the component
- `.mvs-card__header` — an element inside the component
- `.mvs-card--compact` — a variant of the component
- `.mvs-card.is-active` — runtime state (preferred for JS-toggled state over modifier classes)

### Anti-patterns (never do)

| Pattern | Problem | What to do |
|---------|---------|------------|
| `.mvs-btn-primary` and `.mvs-primary-btn` both used | Inconsistent — readers can't remember which |  Pick one. The rest of the plugin uses `component-variant` (`.mvs-btn-primary`), so that wins. |
| `.mvs-new-button` / `.mvs-updated-card` / `.mvs-v2-*` | "new" / "v2" / "updated" become meaningless when they're 2 years old | Use descriptive names (`.mvs-btn--compact`, `.mvs-card--highlighted`). |
| `.mvs-red` / `.mvs-big` | Couples name to visual rather than purpose — breaks when the visual changes | Use purpose: `.mvs-danger`, `.mvs-emphasis`. |
| `.mvs-bp-X` used on non-BP surfaces | Lies about scope | Rename to `.mvs-X` or `.mvs-<actual-scope>-X`. |

---

## 3. PHP class and namespace names

### Namespace structure

All plugin classes are under `WPMediaVerse\`. The namespace path mirrors the file path:

- `includes/Services/UploadService.php` → `WPMediaVerse\Services\UploadService`
- `includes/REST/Controller/MediaController.php` → `WPMediaVerse\REST\Controller\MediaController`
- `includes/Integrations/BuddyPress/ActivityFormIntegration.php` → `WPMediaVerse\Integrations\BuddyPress\ActivityFormIntegration`

### One class per file

Every `.php` file in `includes/` contains exactly one top-level class. The class name matches the file name. PSR-4 autoload relies on this.

### Suffix conventions

| Suffix | Meaning | Example |
|--------|---------|---------|
| `Service` | Business logic, typically a singleton in the container | `UploadService`, `ReactionService` |
| `Controller` | REST endpoint class, extends `WP_REST_Controller` | `MediaController`, `AlbumController` |
| `Repository` | Data access, wraps a custom table | `MediaRepository` |
| `Manager` | Coordinates multiple sub-components | `BuddyPressManager` |
| `Integration` | Bridge to a specific third-party plugin/feature | `ActivityFormIntegration` |
| `Provider` | Pluggable strategy for an interface | `OpenAIProvider` |
| `Registrar` | Registers things at WP load time (CPTs, taxonomies, settings) | `SettingsRegistrar`, `BlockRegistrar` |

Don't invent new suffixes without discussion. If none of these fit, the class may be doing too much or the wrong thing.

### Anti-patterns

| Pattern | Problem |
|---------|---------|
| `Helper` / `Util` / `Manager` as the whole name | No information content — "helper for what?" |
| `DataManagerFactoryBuilderProvider` | Japa-style over-layering. If you need four suffixes, extract. |
| Class name differs from file name | Breaks PSR-4, requires manual autoload workaround |

---

## 4. Method names

### Verb-first

Public methods are verbs or verb phrases: `upload()`, `get_media()`, `register_routes()`, `permission_check()`. Not `media()` or `upload_handler()`.

### Consistency within a class

If one method in a class says `get_*`, the sibling that fetches a different thing also says `get_*` (not `fetch_*` or `retrieve_*`). Mix only when the semantics truly differ (e.g., `get_*` returns cached, `fetch_*` bypasses cache — then the difference is documented).

### Boolean queries

Methods returning `bool` start with `is_`, `has_`, `can_`, `should_`: `is_owner()`, `has_albums()`, `can_upload()`. Never `check_ownership()` if it returns a bool.

### Private helpers

Prefix with nothing visible — `private function build_query()`, not `_build_query()`. The `private` keyword is the signal; the underscore prefix is redundant and inconsistent with WP core convention.

---

## 5. Hook names

**CLAUDE.md Coding Rule #5.** All custom hooks use `mvs_` prefix, snake_case.

### Verb form for actions, noun form for filters

- Actions: `mvs_media_uploaded` (past tense, event happened), `mvs_before_upload_form`, `mvs_after_save_settings`
- Filters: `mvs_media_types`, `mvs_ai_providers`, `mvs_default_privacy` (the thing being filtered, no verb)

### Stability contract

A published hook name is an extension API. Renaming it breaks Pro and third-party extensions. **Never silently rename a hook.** When a hook's name is wrong:

1. Add a new correctly-named hook
2. Keep the old hook firing for ≥2 minor versions
3. `_deprecated_hook()` notice on the old one
4. Document the rename in `CLAUDE.md` Recent Changes

### Scope naming

If a hook is specific to an integration, include the integration in the name:

- `mvs_bp_activity_media_attached` (BP-specific)
- `mvs_woo_product_media_linked` (WooCommerce-specific, hypothetical)

Generic hooks don't need a scope segment:

- `mvs_media_uploaded` (fires regardless of where upload originated)

---

## 6. REST route naming

Routes under `mvs/v1/` namespace. Route paths follow WP REST conventions: plural nouns, lowercase, kebab-case for multi-word:

- `/mvs/v1/media` — collection
- `/mvs/v1/media/{id}` — item
- `/mvs/v1/media/{id}/reactions` — sub-collection
- `/mvs/v1/signed-urls` — multi-word kebab-case

Never `/mvs/v1/getMedia` (camelCase) or `/mvs/v1/media_list` (snake_case).

---

## 7. Database table and column names

### Tables

All custom tables prefix `{$wpdb->prefix}mvs_`, plural noun, snake_case:

- `mvs_media_index`
- `mvs_reactions`
- `mvs_media_stats`

### Columns

Column names are snake_case. `id` for primary key. Foreign keys use `<entity>_id`: `media_id`, `user_id`, `group_id`.

Timestamps: `created_at`, `updated_at`, `deleted_at` (not `creation_time` or `modified_on`).

---

## 8. JavaScript identifiers

### Functions and variables

camelCase: `openLightbox()`, `renderPreview()`, `mediaList`.

### Event handlers

Prefix `on`: `onClick`, `onUpload`, `onDismiss`.

### DOM data attributes

kebab-case: `data-media-id`, `data-lucide`, `data-wp-on--click`.

### Class-like objects and constants

PascalCase for constructors/classes, SCREAMING_SNAKE for constants:

```js
class MvsLightbox { ... }
const MVS_MAX_UPLOAD_SIZE = 50 * 1024 * 1024;
```

### Global namespaces

`window.mvs*` for anything the plugin exposes globally. Never pollute the top-level global (`window.Lightbox = …`). Good: `window.mvsLightbox`, `window.mvsActivityMedia`.

---

## 9. File names

### PHP

PSR-4 convention — file name matches class name exactly (including case): `UploadService.php`, not `upload-service.php` or `uploadService.php`.

Exception: `includes/` files that don't define a class (bootstrap scripts, template includes) use `kebab-case.php`.

### CSS / JS

kebab-case: `bp-integration.css`, `shared-ui-frame.css`, `bp-activity-media.js`. Prefix with `mvs-` only if the file is shipped as an enqueue handle that might collide globally — in practice, the enqueue handle is prefixed (`'mvs-frontend'`), not the filename. Avoid security-flagged tokens like `shell`/`exec` in filenames — customer WAFs may block the file (`shared-ui-shell.css` was renamed to `shared-ui-frame.css` in 1.2.1 for this reason).

### Templates

kebab-case under `templates/`:

- `templates/admin/settings-page.php`
- `templates/partials/shared-ui-frame.php`

---

## 10. i18n strings

All user-facing strings use the text domain `wpmediaverse`:

```php
__( 'Upload Media', 'wpmediaverse' )
esc_html__( 'No results', 'wpmediaverse' )
esc_attr__( 'Close', 'wpmediaverse' )
_n( '%d item', '%d items', $count, 'wpmediaverse' )
```

Never hardcode the domain elsewhere. Don't invent variants (`wpmediaverse-pro`, `wpmediaverse-admin`). One domain, one `.pot` file, all strings.

### String stability

Changing a string's wording after release invalidates existing translations for that string. If the wording must change:

- Keep the old string as a fallback where sensible
- Add the new string as a separate `__()` call
- Update all call sites
- Run `grunt makepot` to regenerate the `.pot`

---

## 11. Option and meta key names

### `wp_options`

All plugin options prefix `mvs_`: `mvs_default_privacy`, `mvs_allowed_file_types`, `mvs_ai_provider`.

### Post meta

Prefix `_mvs_` (underscore hides from Custom Fields UI): `_mvs_view_count`, `_mvs_ai_tags`.

### User meta

Prefix `mvs_`: `mvs_upload_count`, `mvs_follower_count`.

---

## 12. CSS custom properties (design tokens)

All plugin tokens prefix `--mvs-`:

- `--mvs-primary`, `--mvs-surface`, `--mvs-border`
- `--mvs-space-1` through `--mvs-space-7`
- `--mvs-z-modal`, `--mvs-z-toast`

Tokens live in `frontend.css` `:root { }` block. `admin.css` currently hardcodes values because `frontend.css` doesn't load on wp-admin — this is a known gap (see `CSS-ORGANIZATION-RULES.md` §1).

Don't invent theme-specific tokens (`--mvs-reign-primary`). Instead, tokens can reference theme variables as fallback chains:

```css
--mvs-primary: var(--color-theme-primary, var(--brand, #0073aa));
```

---

## 13. Relationship to other rules

- **`CLAUDE.md` Coding Rule #5** (hook prefix) — §5 expands this.
- **`qa/CSS-ORGANIZATION-RULES.md`** §1–§2 — applies §2 (class name scope prefix) to file placement.
- **`qa/PHP-ORGANIZATION-RULES.md`** §2 — naming discipline meets file discipline; a class whose name lies about scope often lies about where it should live too.

---

## 14. New-code naming checklist

When adding a new class / function / hook / CSS class / DB column:

- [ ] Does the name match the established convention in §2–§12 for its type?
- [ ] Does the prefix (if any) accurately reflect scope? (§1)
- [ ] If scope widens later, is the name still accurate, or will it need a rename?
- [ ] Does the name tell a future reader what it does without having to read the body?
- [ ] Is the name unique — no existing class/function with a similar name that does something different?
- [ ] For hooks: is this name I'm willing to support for ≥2 minor versions?

If any is "no", reconsider the name before committing.

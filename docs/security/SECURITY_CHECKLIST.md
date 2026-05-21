# WPMediaVerse Security Checklist

Use this checklist on every pull request that touches PHP. All 19 checks must pass before merge. Items marked (auto) can be verified with the automated tools listed at the bottom.

---

## Input Layer

- [ ] All `$_GET` / `$_POST` / `$_REQUEST` values sanitized with the appropriate function (`sanitize_text_field`, `absint`, `sanitize_email`, `wp_kses_post`, etc.) before any use.
- [ ] File uploads validated with `wp_check_filetype_and_ext()` and a server-side size check against `mvs_max_upload_size` (never trust `$_FILES['size']` alone).
- [ ] JSON request bodies decoded with `json_decode()` and every field validated individually - no pass-through of the raw decoded object into queries or output.

---

## Authentication & Authorization

- [ ] Nonce verified on **all** form submissions (`check_admin_referer()`) and AJAX handlers (`check_ajax_referer()`).
- [ ] `current_user_can()` checked before any write operation (insert, update, delete) - the nonce alone is not sufficient.
- [ ] REST endpoints define a `permission_callback` that returns a `WP_Error` or `false` on failure. `__return_true` is **never** acceptable on write routes.
- [ ] Custom capabilities used where appropriate: `manage_mvs_media` for owner-level actions, `moderate_mvs_media` for moderation actions. Do not fall back to `manage_options` for media-specific gates.

---

## Database

- [ ] All database queries use `$wpdb->prepare()` with typed placeholders (`%d`, `%s`, `%f`). No string interpolation in SQL. (auto)
- [ ] No raw string interpolation in SQL - variables are never placed directly inside a query string.
- [ ] `LIKE` queries escape wildcards via `$wpdb->esc_like()` **before** passing the value to `$wpdb->prepare()`.

---

## Output

- [ ] All output escaped at the point of echo: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, or `wp_json_encode()` depending on context. (auto)
- [ ] Rich HTML content (user-supplied descriptions, captions) sanitized with `wp_kses_post()` before storage and/or output.
- [ ] No raw `echo $variable` - every variable is wrapped in an escape function. Linting will flag unescaped output.

---

## AJAX / REST

- [ ] AJAX handlers call `check_ajax_referer( 'mvs_nonce', 'nonce' )` or `wp_verify_nonce()` before processing.
- [ ] REST responses do not expose sensitive data (password hashes, private keys, internal user emails, full server paths). Review the response schema in `get_item_schema()`.
- [ ] Expensive or public REST endpoints (search, feed) are protected by the `RateLimiter` middleware or a manual transient-based throttle.

---

## File System

- [ ] No user-controlled values (filenames, paths derived from request input) are used directly in filesystem operations (`file_get_contents`, `fopen`, `wp_get_image_editor`, etc.). Paths must be constructed from a safe base directory.
- [ ] Upload directories include an `.htaccess` rule denying direct PHP execution (the plugin provisions this on activation; verify it has not been removed).
- [ ] Temporary files created during processing (thumbnails, watermark intermediates) are deleted in a `finally` block or equivalent cleanup callback.

---

## Automated Checks

Run these tools before opening a PR. CI will also run them and block merge on failure.

**WPCS / PHPCS**

```bash
composer run phpcs                        # Full ruleset scan
```

Or via MCP:

- `wppqa_run_code_checks` - full quality scan including WPCS
- `wpcs_check_file` - single-file WPCS scan
- `wpcs_phpstan_check` - PHPStan static analysis (detects SQL injection, type errors, undefined vars)

**PHPStan**

```bash
composer run phpstan
```

PHPStan is configured in `phpstan.neon.dist` with WordPress stubs. New violations must not be added to `phpstan-baseline.neon` without a documented reason.

**Unit tests**

```bash
./vendor/bin/phpunit
```

Any PR that introduces a new AJAX handler, REST endpoint, or service method must include a corresponding unit test.

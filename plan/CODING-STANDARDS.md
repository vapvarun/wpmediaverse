# WPMediaVerse — Coding Standards

## PHP

### Standard
- WordPress Coding Standards (WPCS) via PHP_CodeSniffer
- PSR-4 autoloading: `WPMediaVerse\` → `includes/`, `WPMediaVersePro\` → `includes/`
- WPCS exclude: `WordPress.Files.FileName` (for PSR-4 class filenames)
- PHP 7.4+ minimum

### Naming

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `UploadService`, `MediaController` |
| Methods | snake_case | `create_item`, `get_allowed_types` |
| Constants | UPPER_SNAKE | `MVS_VERSION`, `MVS_PLUGIN_DIR` |
| Hooks (free) | `mvs_` prefix | `mvs_media_uploaded` |
| Hooks (pro) | `mvs_pro_` prefix | `mvs_pro_watermark_applied` |
| Options (free) | `mvs_` prefix | `mvs_max_upload_size` |
| Options (pro) | `mvs_pro_` prefix | `mvs_pro_stripe_key` |
| Meta keys | `_mvs_` prefix | `_mvs_privacy`, `_mvs_group_id` |
| CSS classes | `mvs-` prefix | `mvs-media-grid`, `mvs-player-controls` |
| JS stores | `mvs/` namespace | `mvs/media-player`, `mvs/dashboard` |
| REST namespace | `mvs/v1` | `/wp-json/mvs/v1/media` |
| Block namespace | `mvs/` | `mvs/media-grid` |
| Tables | `{$wpdb->prefix}mvs_` | `wp_mvs_follows` |
| Free namespace | `WPMediaVerse\` | `WPMediaVerse\Services\UploadService` |
| Pro namespace | `WPMediaVersePro\` | `WPMediaVersePro\Payments\StripeGateway` |

### Documentation

**Every file** gets a header:
```php
/**
 * Service name and purpose.
 *
 * @package    WPMediaVerse
 * @subpackage Services
 * @since      1.0.0
 */
```

**Every public/protected method** gets full PHPDoc:
```php
/**
 * Handle a file upload.
 *
 * @since 1.0.0
 *
 * @param array $file    $_FILES array element.
 * @param int   $user_id Uploading user ID.
 * @param array $args {
 *     Optional arguments.
 *     @type string $title   Media title.
 *     @type string $privacy Privacy level.
 * }
 * @return int|WP_Error Media post ID on success.
 */
```

**Every hook** gets inline docblock:
```php
/**
 * Fires after a media item is uploaded.
 *
 * @since 1.0.0
 *
 * @param int   $media_id The new media post ID.
 * @param int   $user_id  The uploading user's ID.
 * @param array $args     Arguments passed to handle().
 */
do_action( 'mvs_media_uploaded', $media_id, $user_id, $args );
```

### Security Rules
- All user input: `sanitize_text_field()`, `absint()`, `esc_url()`
- All output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- All DB queries: `$wpdb->prepare()`
- All REST endpoints: `permission_callback` (never `__return_true` on write)
- All forms: nonce verification
- File uploads: server-side MIME validation (never trust client)
- EXIF GPS: always strip
- API keys: never expose in REST responses

---

## JavaScript

### Standard
- WordPress Interactivity API exclusively
- Zero jQuery, zero legacy IIFE, zero `wp_localize_script`
- Build: `@wordpress/scripts` with multi-block config
- Stores: one per block/template, cross-store via `store('mvs/other')`

### DOM Safety
- Safe methods only: `createElement`, `textContent`, `classList`
- Never `innerHTML` — XSS prevention
- All REST calls: `X-WP-Nonce` header + `credentials: 'same-origin'`

### Build Command
```bash
npx wp-scripts build --webpack-src-dir=src/blocks --output-path=build/blocks --experimental-modules
```

---

## CSS

### Architecture
- BEM-inspired with `mvs-` prefix
- Files: `assets/css/frontend.css` (public), `assets/css/admin.css` (admin)
- Pro files: `assets/css/pro-frontend.css`, `assets/css/pro-admin.css`
- Mobile-first responsive
- Breakpoints: 480px, 768px, 1024px
- No `!important` — ever

---

## Testing

### PHP
- PHPUnit with `wp-phpunit` test suite
- Coverage target: 80%+ services, 60%+ controllers
- Test file naming: `{ClassName}Test.php` mirroring `includes/` structure
- `$wpdb->replace()` (not `insert()`) for tables with unique constraints in setUp

### Manual QA
- See `docs/QA-CHECKLIST.md`
- Browser matrix: Chrome, Firefox, Safari, Edge (latest 2)
- Mobile: iOS Safari, Android Chrome

---

## Versioning

- Semantic Versioning: MAJOR.MINOR.PATCH
- Major: breaking hook changes, DB migrations
- Minor: new features, new hooks
- Patch: bug fixes, security
- Deprecation: 2 minor versions via `_deprecated_hook()`, removed next major

## Release Checklist

1. All PHPUnit tests pass
2. WPCS clean (`--exclude=WordPress.Files.FileName`)
3. `CHANGELOG.md` updated
4. `readme.txt` version bumped
5. `.pot` file regenerated
6. `.distignore` verified
7. Manual QA passed
8. Security checklist passed
9. Git tag on GitHub
10. Build zip for distribution

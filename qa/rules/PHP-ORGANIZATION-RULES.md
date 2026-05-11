# PHP Organization Rules

> **Rule (standing):** PHP class files in this plugin have a narrow, stated scope. When a class drifts past that scope, it becomes unreadable, accumulates debt, and leaks responsibility into sibling classes. Split before drift becomes permanent.
>
> **Why this rule exists:** The Known Debt table in CLAUDE.md currently lists 4 god classes (`MessagingService` 1606L, `Plugin` 1208L, `MediaController` 1105L, `MessagingController` 803L) that were split from larger originals in prior work. Audit after 2026-04 found three more unflagged god classes (`SettingsRegistrar` 928L, `UploadService` 911L, `MediaRepository` 820L). Two sibling integrations (`ProfileTabIntegration` 736L, `GroupTabIntegration` 712L) share ~80% identical method bodies with no base class. The drift is visible. This doc exists so it doesn't keep happening.

---

## 1. File size and method size

| Limit | Threshold | What to do when exceeded |
|-------|-----------|---------------------------|
| **Max file size** | 500 lines | File is tech debt. Add to `CLAUDE.md` Known Debt table. Next edit must extract code out — never add more lines. |
| **Max method size** | 50 lines | Extract helpers or delegate to a service. A 50-line method is usually doing ≥2 things; name each thing, extract it. |
| **Max class responsibility** | "Can you describe it in one sentence without the word 'and'?" | If you say "X handles A and B", that's two classes. |

### Enforcement today

Not automated. Enforcement relies on reviewer diligence + CLAUDE.md Rule #1. Aspirational: PHPCS custom sniff that fails PRs adding lines to files over 500L until the file drops back under.

### The god-class cycle

Every god class in the plugin started under 500 lines. They all grew the same way:

1. Class does one thing.
2. A feature request adds a related thing — "it's just one more method".
3. Repeat 10x.
4. Now the class does many things and nobody wants to split it because the tests (if any) are coupled to the current shape.

**Prevent by refusing step 3.** If the next "just one more method" puts the file near 500 lines, split the class first, then add the method.

---

## 2. No inline HTML in PHP classes

**CLAUDE.md Coding Rule #4** already says this. Restating in full here because it's violated extensively today.

### What counts as a violation

- `echo '<tag>...'` inside a class method
- `?>` HTML `<?php` heredoc-style mixing inside a class method
- `printf('<tag %s>', ...)` rendering actual HTML structure (arguments you escape and pass are fine; building structural HTML is not)
- Assembling an HTML string by `.= '<tag>'` concatenation, then returning it from a class method

### What's allowed

- `templates/**/*.php` — the dedicated template directory. Anything can render HTML here.
- `src/blocks/*/render.php` — block render callbacks. These ARE templates.
- `templates/admin/**/*.php` — admin templates.
- A class method that calls `load_template( $path, false, $args )` or `require` on a file under `templates/`.

### Known violators (to be cleaned up separately)

- `includes/Integrations/BuddyPress/ProfileTabIntegration.php` — ~200 lines of inline HTML + `<script>` in render methods
- `includes/Integrations/BuddyPress/GroupTabIntegration.php` — same pattern, near-identical content
- `includes/Integrations/BuddyPress/ActivityContentIntegration.php` — inline markup for activity media wrappers

These predate the strict version of Rule #4. They don't get grandfathered — they become the debt that's paid down next. But the rule applies immediately to *new* code: no PR adding new inline HTML to class methods is mergeable, regardless of how much inline HTML already exists in the file.

### Why

Inline HTML in PHP classes:

- Is unescapable at scale — every edit risks introducing an XSS
- Can't be previewed visually without running the full PHP
- Mixes concerns — the class starts owning "what to render" + "how to render", which is two classes
- Makes translators' lives miserable — long HTML strings with interpolated placeholders are brittle

---

## 3. No inline `<script>` echoed from PHP

A class method that echoes a `<script>...</script>` block (even with `printf`, heredoc, or concatenation) is a bug. JS belongs in `.js` files and gets enqueued properly.

### Why it's worse than inline HTML

Inline JS inside PHP means:

- No minification — the JS ships raw to every pageview
- No caching — the JS is in the page HTML, not a cacheable separate resource
- No RTL/LTR or minified variants (grunt only processes files in `assets/js/`)
- Impossible to iterate on without a PHP deploy
- No source maps or debugging tools work on it
- It inherits all the escaping problems of inline HTML, plus JS-specific ones

### Pattern

- Move JS to `assets/js/<feature>.js`
- Enqueue with `wp_enqueue_script( 'mvs-<feature>', … )` in the integration's existing enqueue hook
- Pass runtime data with `wp_localize_script( 'mvs-<feature>', 'mvs<Feature>Data', [ 'restUrl' => …, 'nonce' => … ] )`
- In the JS file, read `window.mvsFeatureData`

### Known violators

Same list as §2: `ProfileTabIntegration`, `GroupTabIntegration`, `ActivityContentIntegration` all contain sizeable echoed `<script>` blocks. Same deal — rule applies to new code immediately; old violations get cleaned up as separate work.

---

## 4. Enqueue consistency

Every integration class that renders HTML must enqueue the styles and scripts needed to make that HTML render correctly. Inconsistencies between sibling integrations cause bugs like the one we hit in 2026-04:

- `ProfileTabIntegration::enqueue_assets()` → `wp_enqueue_style('mvs-frontend'); wp_enqueue_style('mvs-bp-integration');`
- `GroupTabIntegration::enqueue_assets()` → same
- `ActivityFormIntegration::enqueue_activity_media_scripts()` → **only** `wp_enqueue_style('mvs-frontend')` (missing `mvs-bp-integration`)

The missing line meant `bp-integration.css` never loaded on activity composer screens, which forced all BP-activity CSS to live in `frontend.css`, which caused the 2500-line migration we just finished.

### The rule

**If an integration renders BP markup (anything scoped under `#buddypress` or `.activity-list`), it MUST enqueue both:**

```php
wp_enqueue_style( 'mvs-frontend' );
wp_enqueue_style( 'mvs-bp-integration' );
```

And if that markup includes `<i data-lucide>` attributes:

```php
wp_enqueue_script( 'mvs-lucide' );
```

### Aspirational pattern

All BP integrations should share a trait or parent class with a single `enqueue_bp_assets()` method. When the enqueue pattern evolves (e.g., a new shared stylesheet is added), one place changes, not N. See §6 below for the base-class rule that covers this.

---

## 5. No silent failures

**CLAUDE.md Coding Rule #8:** no bare `return false`. Every failure path either:

- Returns a `WP_Error` with a specific code + translatable message (for REST / AJAX / service methods called by controllers)
- Logs via `\WPMediaVerse\Services\LoggerService::warn()` or `::error()` (for fire-and-forget handlers)
- Both, if the caller needs the error object AND the failure is worth a log line for debugging

### Why

`return false` makes debugging impossible. When a customer reports "X doesn't work", the plugin has no record of what failed or why. Every silent failure we've debugged in the last year took 3-5x longer than it would have with a log line.

### Bare `return;` is a separate rule

`return;` (no value) in a **render path** without emitting a visible empty state is covered by `qa/RENDER-STATE-RULES.md` — it's a user-visible bug (blank region), not a logging bug. Both rules apply in render contexts.

---

## 6. Sibling classes with near-identical method bodies must share a base class

When two classes have ≥50% of their methods with near-identical bodies, they belong in a parent class with the shared methods lifted.

### Current example (not yet fixed)

`ProfileTabIntegration` and `GroupTabIntegration` are 80% duplicate. Every BP tab feature today requires editing both files in parallel; the "forgot to update the other one" bug is a matter of time, not possibility.

### The rule

Refactor trigger: when someone is writing a third sibling (adding a hypothetical `SiteActivityTabIntegration` that would duplicate another 80% of the pattern), stop and extract a `BaseBPTabIntegration` first. Two siblings is tolerable; three is an architectural smell that will bite on the next edit.

When extracting:

- Parent is `abstract`.
- Shared methods (enqueue, upload form render, album form render, upload JS wiring) are on the parent and final / non-abstract.
- Context-specific methods (`is_own_profile` vs `is_group_member`, tab naming, tab URL building) are abstract or hookable.
- Subclasses stay under 200 lines.

### Why two is tolerable

Duplication at n=2 is "parallel texts" — often easier to read side-by-side than a generic abstraction. At n=3 the genericization pays for itself. Until then, *keep* the duplication — don't build a base class for a future sibling that may never exist.

---

## 7. Service Container discipline

Services are registered in `includes/Core/Plugin.php::register_services()`. 34 keys today. Every key must:

- Be consumed by actual code (retrieved via `$container->get()` somewhere)
- Have a clear owner (the class that constructs it)
- Be documented in the "Service Container Keys" table in `CLAUDE.md`

### The registered-but-unused anti-pattern

If a service is registered but never retrieved, it's dead configuration — delete the registration. Dead container keys are the PHP equivalent of dead CSS selectors.

Audit command (no tool for this yet; manual):

```bash
grep -n "'[a-z_.]*' =>" includes/Core/Plugin.php | awk -F"'" '{print $2}' | sort -u > /tmp/keys.txt
# Then grep each key against the codebase for '->get("<key>")' or "'<key>'" usage.
```

Automate this when the CI detector from `PROCESS-RULES.md` gets built.

---

## 8. Hook naming

**CLAUDE.md Coding Rule #5:** all custom hooks use `mvs_` prefix, snake_case. The 2026-04 audit confirmed 100% compliance today. Keep it.

### What counts

- `do_action( 'mvs_*' )` — always namespace-prefixed
- `apply_filters( 'mvs_*' )` — always namespace-prefixed
- Hook names are **stability contracts**: once published, extension authors (Pro, third-party) depend on them. Renaming a hook is a breaking change.

### When a hook name seems wrong

Add a new correctly-named hook, keep the old one firing (deprecated), log a deprecation notice when the old one has listeners. Never silently rename.

---

## 9. The Free / Pro boundary

**CLAUDE.md Coding Rule #10:** Pro never imports Free classes by namespace. Pro hooks into `mvs_loaded` and uses the `ServiceContainer` for access.

### The rule in specific terms

- No `use WPMediaVerse\...` in `wpmediaverse-pro/**/*.php`
- No `new \WPMediaVerse\SomeClass()` in Pro
- Pro communicates with Free by: (a) listening to hooks Free fires, (b) retrieving services from the container Free passes into `mvs_loaded`, (c) registering its own hooks that Free may eventually call

### Why

Pro must be activatable/deactivatable independently. If Pro hard-imports a Free class, a Free version bump that renames/removes that class breaks Pro at activation. Hooks + container are the loose-coupling API.

### Enforcement aspiration

`grep -rn 'use WPMediaVerse\\\\' wpmediaverse-pro/includes/` should return zero matches. Add to CI when available (see `PROCESS-RULES.md`).

---

## 10. Template discipline

When PHP class methods are split into templates per §2, templates themselves follow rules:

- One template = one visual region (a form, a card, a list item, a panel). Not a whole page.
- Templates don't echo data they didn't receive as `$args`. No `get_option()` inside a template — the caller resolves settings and passes them in.
- Templates escape at the edge: `esc_html( $args['title'] )`, `esc_attr( $args['id'] )`, `esc_url( $args['link'] )`. Never trust that "the caller already escaped".
- Templates don't enqueue scripts/styles. Enqueue happens in the PHP class that loads the template.
- Template i18n strings use the `wpmediaverse` text domain.

---

## 11. Relationship to other rules

- **`CLAUDE.md` Coding Rules** #1 (file size), #2 (method size), #4 (admin HTML in templates), #5 (hook prefix), #8 (error handling), #10 (Pro boundary), #11 (render fallthrough) — this doc is the expanded spec for each.
- **`qa/CSS-ORGANIZATION-RULES.md`** — the mirror doc for CSS.
- **`qa/RENDER-STATE-RULES.md`** — the specialized spec for §5 render-path return behavior.
- **`qa/NAMING-RULES.md`** — governs what class/method/hook names are allowed to be called.
- **`qa/PROCESS-RULES.md`** — how these rules become machine-checked.

---

## 12. New-code checklist

Before merging a PR that adds or modifies PHP:

- [ ] No file touched is over 500 lines after the change (or, if already over, the change removes lines)?
- [ ] No method in the diff is over 50 lines?
- [ ] No new inline HTML in PHP classes outside `templates/` (§2)?
- [ ] No new inline `<script>` echoed from PHP (§3)?
- [ ] All integrations that render BP markup enqueue both `mvs-frontend` and `mvs-bp-integration` (§4)?
- [ ] No new `return false` without a `WP_Error` or log line (§5)?
- [ ] No new duplication with a sibling class that would make §6 trigger on the next edit?
- [ ] No new Service Container keys without consumers, no deletion of a key still in use (§7)?
- [ ] All new hook names use `mvs_` prefix + snake_case (§8)?
- [ ] No Pro code imports Free namespaces (§9)?
- [ ] All new templates follow §10 (one region, args-only data, escape at edge, no enqueue, text domain)?

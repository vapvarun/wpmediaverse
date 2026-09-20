# Wbcom Authorization Guard Standard — declare who may do what, and let a test prove it

Synced copy. Canonical source is `~/.claude/skills/wp-plugin-release/references/authorization-guards.md`
- edit it there, then re-sync here. **This plugin pair is the reference implementation**, so the
manifests and tests named below are the ones in this repo and in `../wpmediaverse-pro`. **Reference implementation: `wpmediaverse` + `wpmediaverse-pro`
(`audit/route-authority.json`, `audit/capability-map.json`, `audit/render-authority.json`,
`audit/admin-action-authority.json` and the matching `tests/unit/*AuthorityTest.php`).**
Read those before implementing; do not re-derive.

## Why this exists

The 2.5.1 cycle shipped nine authorisation defects that every other gate passed.
WPCS, PHPStan, PHPUnit, the contract audit and a green browser smoke all said yes
to a REST route that returned private album items to anonymous callers, to a
shortcode with no privacy check at all, and to a capability check that read as
correct and was not. None of those tools knows what a route is *supposed* to
require, because nothing had ever written it down.

A guard writes it down, and then fails when the code and the writing disagree.

## The shape — every guard is the same three parts

1. **A manifest** (`audit/<surface>-authority.json`) — one entry per thing that
   can be invoked, declaring who may invoke it and on what authority. Hand-reviewed,
   committed, reviewed in the PR like code.
2. **A test** that reads the **live registry**, not the source tree: `rest_get_server()->get_routes()`,
   `$GLOBALS['shortcode_tags']`, `WP_Block_Type_Registry::get_instance()`, `$GLOBALS['wp_filter']`.
   It fails when the code has something the manifest does not, when the manifest has
   something the code no longer has, and when the declared authority is not the one
   in the source.
3. **A mutation proof.** Break the code on purpose, watch the test go red, revert.
   A guard that has never been seen red is not a guard. Record the mutations you ran
   in the PR body.

The manifest is not documentation. It is the expected value in an assertion.

## The four guards

| Guard | Live registry it walks | What it proves |
|---|---|---|
| **Route authority** | `rest_get_server()->get_routes()` | Every REST route has a `permission_callback`, and it is the one declared. No route is public by omission. |
| **Capability map** | The role objects + every `current_user_can()` in source | Each capability's scope is declared (own / any / site / feature) and matches the roles that actually hold it. |
| **Render authority** | `$GLOBALS['shortcode_tags']`, `WP_Block_Type_Registry` | Every shortcode and block that renders someone's content applies the same privacy rule the REST route does. |
| **Admin action authority** | `$GLOBALS['wp_filter']` for `admin_post_*`, `admin_post_nopriv_*`, `wp_ajax_*`, `wp_ajax_nopriv_*` | Every handler checks a nonce **and** a capability, and any handler that checks only "logged in" says why. |

Two surfaces have no guard yet and are the obvious next ones: **scheduled work**
(cron + Action Scheduler callbacks, which run as nobody) and **outbound HTTP**
(where the request target comes from). Write them when a plugin gives you the excuse.

## The traps — each of these has already produced a guard that lied

1. **The vacuous pass.** `is_admin()` is false in a PHPUnit process, so a walk of
   `wp_filter` for `admin_post_*` finds nothing and every check passes on an empty set.
   Same class: a regex that matches no route, so the loop tests zero routes — one
   missing backslash did exactly that on MediaVerse's album probe, and it passed
   against provably vulnerable code. **Every guard carries a tripwire test that fails
   when the registry it walks comes back empty**, and where a registry is genuinely
   unavailable in the test process, the walk is a union of the live registry and a
   source sweep with comments stripped (`token_get_all()` — a commented-out
   registration still matches a raw regex).
2. **Ownership by name prefix.** `WPMediaVersePro\` does not start with `WPMediaVerse\`
   only because of a trailing backslash. Resolve which plugin owns a callback by
   **reflection on the callback's file path**, not by matching a namespace string.
3. **Declaring a check into existence.** If an entry says it delegates to a guard
   method, the test must read that method's source **and** assert the handler
   actually calls it. Without the second half, `delegates_to` is a way to write a
   check that does not exist. Match with a boundary (`(?<![\w>:$])`) so
   `$this->current_user_can_save()` does not satisfy a search for `current_user_can`.

## Rules

1. **Refuse with 404, not 403**, wherever 403 would confirm that the object exists.
2. **One rule, one place.** A privacy decision lives in one service method that every
   caller — controller, block render, shortcode, CLI — calls. A second copy is how the
   fix reaches the API and misses the page. When you fix an authorisation bug, grep
   every caller before you edit.
3. **Route matching is case-insensitive in WordPress** (`preg_match('@^'.$route.'$@i')`)
   while `get_route()` returns the path as sent. Any gate that compares a route prefix
   must lowercase both sides, or it is bypassable by capitalisation (CWE-863).
4. **Capability names lie about scope.** An `edit_*` capability granted to Subscriber
   means "your own", never "anyone's". The site-wide authority is the `moderate_*` one.
   Declare the scope in the capability map so the next reader cannot misread it.
5. **Every exception is written down, with the reason and the proof.** "Logged in is
   the right authority here" is acceptable only next to the probe that fired the handler
   as a low-privilege user and showed what it could and could not reach.
6. **A conditional entry names what mounts it** (`conditional_on`), so a route that is
   absent because a feature is off cannot be confused with one that was deleted.

## Adopting this in a plugin

1. Generate the first route manifest:
   `php ~/.claude/skills/wp-plugin-release/scripts/route-authority-scan.php <plugin-dir>`
2. Read every entry. The scan proposes; you decide. Fix what is wrong in the code
   before you record it in the manifest — a manifest that documents a hole is worse
   than no manifest.
3. Copy the matching `*AuthorityTest.php` from the reference implementation and point
   its constants at this plugin (namespace prefix, source root, manifest path).
4. Mutate and watch it fail, at least: delete a `permission_callback`, add an undeclared
   route, rename a declared callback, and break the source root so the registry comes
   back empty.
5. Add the test to the plugin's local CI and commit the manifest with the code.
6. Sync this file to `<plugin>/docs/standards/authorization-guards.md`.

## Where it plugs into release

`/wp-plugin-release` **Gate G** runs these: G1 the mechanical sweep
(`scripts/security-sweep.sh`, 19 patterns, `--self-test` so a broken pattern cannot
silently match nothing), G2 the authorisation matrix as a test — that is this standard,
G3 the reviewed diff, G4 a scan of the release candidate with our own WP Vanguard
scanner. Gate G is where a plugin's guards are required to be green, not merely present.

The class ranking Gate G is built around comes from the WP Vanguard corpus
(68,605 issues across 16,195 plugins and 2,165 themes): missing authorisation first
by a wide margin, then unauthenticated stored XSS, unauthenticated disclosure,
arbitrary upload to RCE, SQLi, object injection, SSRF, state change via GET.
The guards above exist because the top three are all this standard's subject.

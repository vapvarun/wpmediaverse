# Process Rules

> **Rule (standing):** Rules that exist only as prose in CLAUDE.md get forgotten. Rules that CI can check don't get forgotten. The plugin's long-term code quality depends on making machine-checkable rules actually machine-checked — not on a human reading CLAUDE.md before every edit.
>
> **Why this rule exists:** The 2026-04 BP CSS migration happened because `.theme-flavor` sat dead for 6+ months, duplicate class/ID rules silently fought each other for equally long, and an `ActivityFormIntegration` enqueue omission shaped the whole BP CSS architecture without anyone noticing. Every one of those was mechanically detectable. None was mechanically detected. This doc exists so that stops being true.

---

## 1. Where rules live

The plugin has four places rules can live. Each place has a purpose; mixing them defeats navigation.

| File | Purpose | Format |
|------|---------|--------|
| `CLAUDE.md` **Coding Rules** table | Canonical short list. One line per rule, linking to its detailed spec if it has one. | Numbered bullets, 1–2 sentences each. |
| `qa/RULES-*.md` | Full spec for a single rule or rule family. Rationale, examples, anti-patterns, enforcement, checklist. | Longer prose, tables, code examples. |
| `qa/WHAT-TO-CHECK.md` **Regression locks** table | Specific visual/behavioral specs that have regressed at least once and must not drift. | Table rows: surface, locked spec, regression history. |
| `.github/workflows/*.yml` + custom lint configs | Machine enforcement. The authoritative list of rules CI actually checks. | CI configs + custom scripts. |

### Flow

1. Someone notices a recurring problem or writes a new architectural decision.
2. The rule goes into `CLAUDE.md` Coding Rules as a short numbered entry.
3. If the rule needs more than one sentence, a detailed spec goes in `qa/RULES-*.md` (or extends an existing one).
4. If the rule is mechanically checkable, it eventually becomes CI.
5. If a bug fix implements the rule for the first time, the spec goes into `qa/WHAT-TO-CHECK.md` regression locks with the bug history.

### Anti-pattern

Don't:

- Put a long rule explanation inline in `CLAUDE.md`. CLAUDE.md is the index; specs live in `qa/`.
- Have the same rule stated three times across three files with slightly different wording. Pick the canonical spec file and link to it.
- Add a rule to `CLAUDE.md` without saying where the full spec is (or acknowledging there isn't one yet).

---

## 2. Rules that must eventually be machine-checked

Every rule in this table is mechanically detectable. The column "CI status" tracks whether we've actually built the check. A rule without CI is a rule nobody is enforcing — only human memory + reviewer diligence is, and both are unreliable at scale.

| Rule | Source | Detection method | CI status |
|------|--------|------------------|-----------|
| BP CSS rules live in `bp-integration.css`, not `frontend.css` | `CSS-ORGANIZATION-RULES.md` §1 | Stylelint: in `frontend.css`, fail on selectors matching `/#buddypress|\.buddypress-wrap|\.activity-(content|list)|\.mvs-bp-|\.bp-/` | not built |
| No duplicate class-vs-ID rules targeting same element | `CSS-ORGANIZATION-RULES.md` §4 | PostCSS script: parse all CSS, flag any class+ID pair on same element with conflicting declarations | not built |
| No dead CSS selectors (selector with no emitter) | `CSS-ORGANIZATION-RULES.md` §5 | Node script: grep each `.mvs-*` / `#mvs-*` / `#whats-new-*` selector against `includes/`, `src/`, `templates/`, `assets/js/` | not built |
| `!important` has a comment explaining what it fights | `CSS-ORGANIZATION-RULES.md` §3 | Regex: `!important;?\s*$` followed by no adjacent `/\* ... \*/` comment → fail | not built |
| CSS file has top-of-file banner | `CSS-ORGANIZATION-RULES.md` §6 | Script: first 30 lines of each `assets/css/*.css` must contain `Scope:` or `Not scope:` keywords | not built |
| Max file size 500 lines (except Known Debt) | `PHP-ORGANIZATION-RULES.md` §1 | `wc -l` check; compare against Known Debt allowlist | not built |
| No inline `echo '<tag>'` in PHP classes outside `templates/` | `PHP-ORGANIZATION-RULES.md` §2 | PHPCS custom sniff `WPMediaVerse.NoInlineHTML` | not built |
| No inline `<script>` echoed from PHP | `PHP-ORGANIZATION-RULES.md` §3 | PHPCS sniff or grep: `echo\s+['"]<script` → fail | not built |
| BP integrations enqueue `mvs-bp-integration` | `PHP-ORGANIZATION-RULES.md` §4 | Grep: every class in `includes/Integrations/BuddyPress/` with a `wp_enqueue_style('mvs-frontend')` must also enqueue `mvs-bp-integration` | not built |
| No silent `return false` from non-trivial methods | `PHP-ORGANIZATION-RULES.md` §5 | PHPCS sniff checking `return false;` isn't preceded by `WP_Error` construction or `Logger::` call | partial (PHPStan catches some via type annotations) |
| No bare `return;` in render paths | `RENDER-STATE-RULES.md` | Grep in `render.php` files + `render_*` methods; fail if bare `return;` without adjacent `render_*_empty_state()` call | not built |
| Hook names use `mvs_` prefix | `NAMING-RULES.md` §5, `CLAUDE.md` #5 | Grep `do_action\(|apply_filters\(` for anything not starting with `'mvs_` | not built |
| Free / Pro boundary | `PHP-ORGANIZATION-RULES.md` §9 | Grep `use WPMediaVerse\\` in Pro codebase → fail | not built |
| Class name lies about scope (`.mvs-bp-X` used outside BP) | `NAMING-RULES.md` §1 | Grep each `.mvs-<scope>-*` class against emitter files; fail if emitter scope differs | not built |
| Section numbers in `frontend.css` don't repeat | `CSS-ORGANIZATION-RULES.md` §7 | Awk script: extract `N. Title` headers, fail on duplicate `N` | not built |

### How to build these

The detection methods above are specified, but none are yet in CI. When CI gets set up (separate work), these are the checks to build. The rules in the detailed specs are the source of truth for what each check must verify.

Until CI exists, the rules are enforced only by:

- Reviewer diligence (unreliable at scale)
- The new-code checklists at the bottom of each `qa/RULES-*.md` file (PR author self-check)
- Periodic audits (manual, slow)

Treat the "not built" rows as a backlog. CI catches regressions the instant they land; humans catch them weeks later after customers complain.

---

## 3. The debt-tax rule

Rule: **No PR may add lines to a file in the `CLAUDE.md` Known Debt table.** Every edit to a debt file must either reduce its line count or extract code out of it. If neither is possible for the specific change, the PR needs explicit approval in the PR body justifying the addition.

### Why

God classes stay god classes because every edit feels small. The debt tax converts "just one more method" into "reduce first, then add." Files exit the debt list the day they drop below 500 lines.

### Known Debt today (CLAUDE.md)

| File | Lines | Status |
|------|------:|--------|
| `includes/Messaging/MessagingService.php` | 1,606 | God class |
| `includes/Core/Plugin.php` | 1,208 | Bootstrap monolith |
| `includes/REST/Controller/MediaController.php` | 1,105 | Largest controller |
| `includes/Messaging/MessagingController.php` | 803 | Large controller |

Three more identified in 2026-04 audit but not yet added to the debt table:

- `includes/Admin/Settings/SettingsRegistrar.php` (928 lines)
- `includes/Services/UploadService.php` (911 lines)
- `includes/Repository/MediaRepository.php` (820 lines)

These should be promoted to the Known Debt table so the debt tax applies to them too.

---

## 4. Regression-lock workflow

`qa/WHAT-TO-CHECK.md` has a "Regression locks" table. Every time a bug is fixed because a visual or behavioral spec drifted, the fix commit adds a row there with:

- Surface (e.g., "Activity composer attach-media button")
- Locked spec (e.g., "36px min-height, 4px border-radius, …")
- Regression history (commits that broke it, commits that fixed it)

### When to add a row

- Any bug fix that responds to a customer screenshot (it broke, was visible, shouldn't break again)
- Any architectural decision that took ≥2 commits to get right (future edits shouldn't rediscover the same traps)
- Any specificity war we won (future theme updates might reopen it — the locked spec is how we know we're still winning)

### When NOT to add a row

- Random one-off bugs with no pattern (regression-lock table should be specifications, not a bug log)
- Behaviors covered by unit tests (tests are cheaper than prose; prefer them)
- Specs that are obvious from the code (comment in the source instead)

### Entry quality

A regression-lock row that just says "the button should look right" is useless. Rows should be specific enough to verify mechanically:

- ✅ "Button and select both render at 36px min-height, yDelta 0 between top edges on wb-reign-theme"
- ❌ "Button and dropdown should align"

Specific rows can become automated browser tests or CI checks. Vague rows can't.

---

## 5. Aging / rotation of rules

Rules aren't immortal. A rule that made sense for a version that no longer exists should be removed, not grandfathered indefinitely.

### When to retire a rule

- The reason it existed is gone (e.g., a theme it worked around is no longer supported)
- The codebase restructuring it prevented has happened, and the rule is now trivially satisfied
- It's been superseded by a better rule that covers the same space

### How to retire

1. Remove from `CLAUDE.md` Coding Rules numbered list (renumber remaining)
2. Mark the detailed spec in `qa/RULES-*.md` as "Retired YYYY-MM-DD" at the top of the file (don't delete — historical context for the regression locks that reference it)
3. Document in `CLAUDE.md` Recent Changes why the rule retired

Never silently delete a rule. Someone might be relying on it.

---

## 6. Agent-executable verification

The plugin has a `qa/runbook/` directory with agent-executable steps. Every regression lock in `qa/WHAT-TO-CHECK.md` should eventually have a matching runbook entry the agent can execute:

- Navigate to URL X
- Click element Y
- Assert CSS property Z has value W
- Screenshot for record

This is the bridge between "specs in prose" and "automated tests without a test framework". An agent reading WHAT-TO-CHECK.md + runbook can verify the plugin in its current state matches every locked spec.

### Aspiration

Every regression-lock row should have a runbook step within N commits of being added. If the row can't be made agent-executable, it's too vague (see §4).

---

## 7. CLAUDE.md discipline

`CLAUDE.md` at the plugin root is the AI-readable summary of the plugin's architecture. It exists so an AI agent walking into the codebase cold can be productive in one read.

### What belongs in CLAUDE.md

- Quick facts table (version, PHP/WP requirements, namespace, text domain)
- Module map (what lives where)
- Service container key table
- Custom table list
- Coding Rules (short canonical bullets, link to `qa/*.md` specs)
- Known Debt table
- Testing / build commands
- Recent Changes log

### What does NOT belong

- Full rule specs (→ `qa/*.md`)
- Detailed architectural rationale (→ `docs/architecture/*.md`)
- Code samples longer than 3 lines
- Tutorials, FAQs, support notes (→ `docs/`)

### The 500-line cap

Historical precedent: plugins whose CLAUDE.md grew past 500 lines stopped being useful as quick reference. Same cap as the source-file rule. If CLAUDE.md is growing, something that could be a spec is being inlined instead.

---

## 8. Rule discovery

A new developer needs to find the rules. The entry points:

1. **`CLAUDE.md`** at plugin root — first thing they read
2. **`qa/README.md`** — index of QA / spec docs
3. **File-top banners** — every file that owns a scope has a banner explaining what belongs
4. **Error messages from CI** — when CI is built, violations should link to the spec file

An idiomatic developer journey:

1. Open a PHP file to make an edit → banner tells them the file's scope
2. Hit a rule violation in CI → error message links to `qa/PHP-ORGANIZATION-RULES.md` §X
3. Read the rule's "new-code checklist" → self-check the rest of the change
4. If unclear, check `CLAUDE.md` Coding Rules for the one-line canonical version
5. If still unclear, check `qa/WHAT-TO-CHECK.md` for regression locks that may apply

### The failure mode to prevent

"There's no rule about this" is almost never true — it usually means the developer didn't find the rule. Every rule document ending with a navigation section and cross-links to sibling docs reduces this failure mode.

---

## 9. The short version (for rapid reference)

- **CLAUDE.md Coding Rules** = the index. Always start there.
- **qa/*.md** = the specs. Full rule detail + checklist.
- **qa/WHAT-TO-CHECK.md regression locks** = specific things that broke once; don't let them break again.
- **File-top banners** = "this file owns X, not Y."
- **CI** = aspirationally machine-checks every rule that's mechanically checkable. Build the checks. Don't rely on memory.
- **Debt tax** = don't add lines to Known Debt files.

---

## 10. Relationship to other rules

- **`CLAUDE.md` Coding Rules** — this doc's §1 and §7 describe how that list is maintained.
- **`qa/CSS-ORGANIZATION-RULES.md`**, **`qa/PHP-ORGANIZATION-RULES.md`**, **`qa/NAMING-RULES.md`**, **`qa/RENDER-STATE-RULES.md`** — the detailed specs this doc references. Together they form the complete rulebook.
- **`qa/WHAT-TO-CHECK.md`** — where rules become concrete regression-lock rows with specific commits.

## 5. Verify at the right tier

CLAUDE.md already says this; it is repeated here because it was violated
repeatedly during the 2.4.1 cycle and the cost was visible.

| When | Run |
|---|---|
| Per fix | the pre-push hook (it already runs local-CI), the tests covering what you touched, and **browser verification of every UI change, including 390px** |
| Per release | the full battery — certs, combo smoke, manifest refresh, contract audit, pristine install of the built zip |

During 2.4.1 the full battery was run after **every card** — roughly six times
where one was needed, plus a PR cycle per fix. Most of it re-proved what the hook
had just proved. The browser check is the part that earns its keep on a bug fix:
it is what caught a regression introduced *while* fixing another card (the
Documents tab, broken by the first cut of the Compete fix).

The failure mode this protects against is not slowness. It is that a gate which
costs more than it catches teaches people to skip gates.

## 6. "Cannot reproduce" is a claim about your fixtures first

A card that will not reproduce is a finding — but only after the environment has
been ruled out.

Card 10264236711 (lightbox fullscreen dead on Activity) was reported here as
"does not reproduce", with evidence: no clone was ever created, every
interception condition matched, the click did nothing. All true, and all
irrelevant — **every media item in that feed pointed at deleted media**, so the
handler bailed out long before the code under test. Seeding one live upload
reproduced the card exactly as written, in seconds.

Before writing "cannot reproduce":

- Confirm the fixtures the repro depends on actually exist and resolve. A row in
  `mvs_media_index`, a live slug, a real BuddyPress friendship.
- Prefer creating the state the card describes over hunting for it. Seeding an
  upload is cheaper than an hour of tracing.
- If the symptom you see differs from the reported one, you have probably found a
  *second* bug rather than disproved the first. Report both.

## 7. Triaging a contract-audit finding

"Read but never written" has three meanings and the category name suggests only
two:

1. the scanner cannot see the write — class constant, dynamically built key, or a
   scheduler (`wp_schedule_event`, `as_enqueue_async_action`) instead of a literal
   `do_action`/`update_option`. **Noise.**
2. the write genuinely does not exist. **Real.**
3. the write exists but nothing can reach it. **Real, and it reads exactly like
   noise.**

Case 3 is why `_mvs_extra_document_credits` sat in the report for months:
`QuotaService::add_credits()` validated against `MEDIA_TYPES` (the MIME-detectable
list, which excludes documents by design) instead of `SUMMARY_TYPES`, so document
credits could never be granted while `deduct_credit()` spent them. Reading the
code shows a write site and looks fine. Only running it shows the write never
happens.

**So: verify case 3 at runtime, not by reading.** And when suppressing, record the
mechanism that hides the finding — never a bare "known good". A suppression for a
bug that was FIXED is a scar, not an exception, and should say so.

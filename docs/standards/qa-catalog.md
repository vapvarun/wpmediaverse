# WordPress Plugin QA Catalog (ALL plugins — normative)

> The single source of truth for **what gets checked, how, and in what order**
> before any card moves to Ready for Testing or any release ships. Applies to
> every Wbcom plugin/theme (100+). Per-plugin repos hold **data only**; the
> process is defined here, once. Each plugin keeps a synced copy at
> `docs/standards/qa-catalog.md`.

## Priority (non-negotiable)

**Tier 1 is presentation & functional flow — what a site owner or member SEES
and DOES in a real browser.** Code audits (WPCS, PHPStan, PHPUnit, coding rules)
are SECONDARY: they run automatically at commit/push, cost zero attention, and
never substitute for a browser pass. Rationale: the Eventonomy 2026-07-02 cycle
produced 17 QA bounces — every one was presentation or flow (theme-fit, dead UI,
SSR↔JS drift, missing entry points); zero were static-analysis catchable. Static
gates were already green the whole time.

```
Tier 1  PRESENTATION & FLOW   (browser — the primary gate)
Tier 2  PRODUCT COMPLETENESS  (no dead UI, 3 entry points, honest upsell)
Tier 3  FUNCTIONAL LOGIC      (REST live, money, roles, scale)
Tier 4  CONTRACT & WIRING     (settings, hooks, REST↔JS, enums)
Tier 5  CODE QUALITY          (secondary — automatic, silent when green)
Tier 6  I18N · DOCS · RELEASE
```

## The one pipeline (wired — every check has a named runner)

One entry point runs everything in order and emits one verdict. No step is
optional; a SKIPPED without written reason = FAIL.

```
VERIFY (pre-RFT / pre-release)
│
├─ 0  BOOT        wp plugin activate + debug.log diff · npm run build ·
│                 RTL parity · migrations idempotent · debug.log stays clean
│                 through the ENTIRE G3 run (diff at end) · G4: upgrade-path —
│                 install PREVIOUS released zip + seed real data → upgrade to
│                 current → data intact, migrations fire (activation hooks do
│                 NOT run on update; the runtime version-gate must)
│
├─ 1  PRESENTATION & FLOW  ←——— the gate that decides RFT
│     1a  Journeys        bin/run-journeys.sh (audit/journeys/*.md; agent +
│                         Playwright MCP + curl + mysql, per-step assertions)
│     1b  Smoke walk      /wp-plugin-smoke (reads docs/qa/qa-config.json,
│                         walks AGENT_SMOKE_RUNBOOK.md in a real browser)
│     1c  Block matrix    scripted page w/ EVERY block → non-empty DOM +
│                         screenshot   (Playwright MCP)
│     1d  Theme-fit       key surfaces × {BuddyX, Reign, TT4} × {light,dark}
│                         × {desktop, 390px} — computed-style asserts: font
│                         inherits theme, accent chain resolves, no theme
│                         button-bleed, no raw hex     (Playwright MCP)
│     1e  SSR↔JS parity   DOM-diff server render vs view.js re-render for
│                         every block with client re-render (Playwright MCP)
│     1f  States          hover/focus/disabled probes · empty/error/loading
│                         forced per async surface · per-field 422 errors
│                         render AT the field           (Playwright MCP)
│     1g  A11y            wppqa_check_a11y + keyboard pass + role=status
│     1h  Console clean   zero JS errors on every visited page
│     1i  390px           every touched surface screenshot-verified
│     1j  Editor side     every block INSERTED in Gutenberg — no crash, preview
│                         renders, inspector controls change output (Playwright)
│     1k  Legacy surfaces every shortcode / widget / template override renders
│                         (BP-era + Woo plugins — not everything is a block)
│     1l  Emails render   every outgoing email opened from the log — branded
│                         shell, placeholders resolved, links work
│
├─ 2  PRODUCT COMPLETENESS
│     2a  Click-everything   every visible tab/button/setting clicked once —
│                            leads somewhere real (no dead UI; a "Saved" tab
│                            requires a Save button)      (journey)
│     2b  3 entry points     every data store: frontend + admin view + REST
│                            (manifest cross-check; exceptions documented)
│     2c  Schema↔UI parity   every meaningful column surfaced (featured →
│                            badge, counts → stats)
│     2d  Free/Pro states    dual-state journey: Pro off (locked upsells
│                            visible) / Pro on (everything live)
│     2e  Owner expectation  settings placement vs WooCommerce/TEC norms;
│                            no one-control sections; 3rd-party under
│                            Integrations              (review checklist)
│
├─ 3  FUNCTIONAL LOGIC
│     3a  REST live       wppqa_check_api — CRUD each resource on the seeded
│                         site; envelope uniform {items,total,…}
│     3b  Role probe      wppqa_probe_roles vs audit/ROLE_MATRIX.md
│     3c  Money exact     checkout journey asserts subtotal/tax/fee/coupon/
│                         total to the cent (incl. inclusive-tax, absorb-fee)
│     3d  State machines  order/RSVP/event transitions; webhook idempotency
│     3e  Background jobs cron/AS fire (wp cron event run probe)
│     3f  Concurrency     two-session "already taken/deleted" journey steps
│     3g  SCALE           wp <plugin> seed --scale (1000+ rows, 500+ users):
│                         pagination + COUNT(*) + indexes + no N+1 (query
│                         budget) + filter/sort usable   (benchmark stage)
│     3h  Notifications   every email/SMS/web notification fires EXACTLY ONCE
│                         on its trigger, respects its settings toggle
│                         (bin/email-coverage-check.sh + trigger journey)
│     3i  Degradation     external API down (gateway, geocoder, SMS, updater)
│                         → graceful user-visible error, no fatal, no data
│                         corruption            (mocked-failure journey)
│     3j  Round-trip      export → reimport (CSV/ICS/JSON) → data equal
│     3k  Time handling   timezone + DST correctness wherever dates matter
│                         (site TZ ≠ event TZ journey data)
│
├─ 3E ENVIRONMENT & COMPAT
│     3E-a  Min versions   activates + G1 journeys pass on min WP / min PHP
│                          (PHPCompatibility sniff at G2; live matrix at G4)
│     3E-b  Multisite      network activate + subsite: no fatals, per-site
│                          tables/options correct
│     3E-c  Object cache   Redis/persistent cache ON — invalidation on writes
│                          still correct (last_changed keys)
│     3E-d  Page cache     cached pages + expired nonces → REST writes still
│                          succeed or re-auth gracefully (classic cache bug)
│     3E-e  Host plugins   addons: BuddyPress / WooCommerce / host theme at
│                          min supported AND latest — integration surfaces
│                          (tabs, activity, checkout) render on both
│     3E-f  Conflict smoke activate alongside the common stack (Elementor,
│                          Yoast, LiteSpeed/W3TC, WooCommerce, BuddyPress) —
│                          no fatals, journeys still pass       (G4)
│     3E-g  Cross-browser  key journeys on WebKit + Firefox     (G4)
│     3E-h  Asset scoping  plugin CSS/JS enqueued ONLY on surfaces that need
│                          them (network-tab probe on an unrelated page)
│
├─ 4  CONTRACT & WIRING
│     4a  Settings wiring    wppqa_check_wiring_completeness + camelCase-aware
│                            trace (render.php, Store→state.config→view.js,
│                            functions.php — naive greps false-positive)
│     4b  Key/hook contract  /wp-contract-audit (+ baseline): orphan keys,
│                            dual keys, consumed-never-fired, dead hide-CSS
│     4c  REST↔JS contract   wppqa_check_rest_js_contract
│     4d  Field parity       bin/field-parity-check.sh (editor ↔ REST write)
│     4e  Enums              wppqa_check_enum_consistency ('published' etc.)
│     4f  Action audit       /action-audit + wppqa_check_plugin_dev_rules
│                            (nonce+cap on every write; WP_Error not false)
│     4g  Secrets            tokens/keys never in REST/admin/CSV/HTML
│                            (grep + security journeys as sentinels)
│
├─ 5  CODE QUALITY  (secondary — runs at G1/G2 automatically, silent when green)
│     5a  php -l · WPCS · PHPStan(+baseline) · JS lint      composer lint/analyse
│     5b  Coding rules       bin/coding-rules-check.sh
│     5c  Arch invariants    bin/architecture-checks.sh + plan/INVARIANTS.yaml
│                            (EP1: Pro→Free via contracts/hooks only)
│     5d  Unit tests         phpunit tests/unit (pure services: money,
│                            recurrence, container)
│     5e  Asset budgets      npm run size
│
└─ 6  I18N · DOCS · RELEASE  (G4 only)
      6a  i18n            WPCS sniff (domain, translator comments, _n) ·
                          make-pot diff empty · date_i18n/number_format_i18n ·
                          logical CSS props · no em-dash in customer strings
      6b  Docs coverage   every block/screen/setting/feature has a dedicated
                          doc (coverage audit vs manifest) · screenshots current
      6c  Dev-friendly    manifest fresh (±5% vs greps) · EXTENDING.md lists
                          every seam · REST-API.md parity · CLAUDE.md current
      6d  Packaging       version triangulation · action-prefix changelog ·
                          dist zip clean · licensing/updater answers ·
                          Free/Pro lockstep · boot-smoke the BUILT ZIP ·
                          readme "Tested up to" current
      6e  Privacy/GDPR    WP personal-data exporter + eraser registered for
                          every PII store (emails, phones, orders) · no PII
                          in logs · privacy-policy snippet registered
      6f  SEO output      structured data (schema.org) validates where the
                          plugin emits it · noindex flags honored
```

## Gates

| Gate | Trigger | Runs | Attention needed |
|---|---|---|---|
| G1 | pre-commit | 0-boot(lint/build) + 5a/5b | none (automatic) |
| G2 | pre-push hook | Tier 4 + 5 (all static) | none (automatic) |
| **G3** | **before ANY card → Ready for Testing** | **Tiers 0–4 (browser included) for touched surfaces; full run per release cycle** | **the primary human/agent gate** |
| G4 | /wp-plugin-release | everything incl. Tier 6 | release owner |

**RFT rule:** no card moves to Ready for Testing unless Tier 1+2 passed in a
real browser for the surfaces it touched. "Static green" is never a reason to
move a card.

**Verifying a card already IN Ready for Testing is a different job** — this
pipeline asks "is the plugin healthy?", QA asks "is this specific claim true,
and did fixing it break something else?" That protocol is
[`wp-card-qa`](../../wp-card-qa/references/rft-verification.md) (regular, per-card QA — its own skill): five questions per card, a
comparable verdict format, and the owner-vs-end-user judgement call.

**Regression rule:** every QA bounce = a missing check. The fix commit MUST add
the journey step/assertion that would have caught it. A recurring bug is a
process failure.

## Per-plugin data files (the only thing a plugin owns)

```
docs/qa/qa-config.json     # site URL, themes, roles, key pages, block list  → scaffolded by /wp-plugin-release-qa
audit/journeys/**/*.md     # runnable walkthroughs (customer/member/admin/security)
audit/manifest.json        # inventory (generated by /wp-plugin-onboard)
audit/ROLE_MATRIX.md       # expected allow/deny per role
plan/INVARIANTS.yaml       # architecture gates
```

`/wp-plugin-onboard`'s bootstrap chain scaffolds these for every plugin it
touches — rollout to the portfolio happens as we work plugins, no big-bang.
The pipeline itself is never copied into a repo.

## Verdict

Every G3/G4 run writes `audit/READINESS.md`: one line per pipeline step —
PASS / FAIL / N-A(reason) / SKIPPED(reason, counts as FAIL). The Basecamp RFT
comment links this verdict. `wppqa_readiness` / `wppqa_certify` provide the
machine-readable equivalent.

# Functional Certification — `wp mvs cert`

A portable, plugin-level behavioural gate. It runs identically on any machine
against a live, activated WP site — no machine-specific QA site required.

## Run it

```bash
wp mvs cert            # all checks (contract + coverage + boot)
wp mvs cert boot       # REST boot smoke only
wp mvs cert contract   # dead-toggle oracle check only
wp mvs cert --porcelain  # machine-readable JSON ledger
```

In CI it is stage 3.2 of `bin/local-ci.sh`, run when `MVS_WP_PATH` points at a
live WP install:

```bash
MVS_WP_PATH=/path/to/wordpress composer ci
```

Pro ships the same gate as `wp mvs-pro cert` (it reuses this engine; activate
the free plugin first).

## The three checks

- **boot** — live-discovers every registered route in the `mvs/v1` namespace
  (from the running REST server, so it is never stale) and dispatches each GET,
  asserting no 500. A thrown fatal is captured as a 500.
- **contract** — for each oracle in `cert-oracles.json`, flips its gating option
  OFF then ON and dispatches the guarded route both times. Passes only when OFF
  emits the `off_code` and ON does not — proving the toggle actually enforces
  (catches dead toggles).
- **coverage** — enforces 100%: every feature toggle must be accounted for by
  EITHER a proven oracle OR a journey runbook that exists on disk. A toggle with
  neither FAILS the gate, so it cannot go green while a feature's enforcement is
  undocumented.

## Extend it

Edit `audit/cert-oracles.json` (the only hand-authored file):

- **Add an in-band toggle** (a route that returns a distinct `WP_Error` code
  when the option is off): add an entry to `contract[]` with
  `{id, kind, route, method, params, off_code}`.
- **Add any feature toggle**: add `{ "id": "mvs_x", "journey": "audit/journeys/..." }`
  to `toggles[]`. If it has an oracle, drop the `journey`. The coverage check
  verifies the journey file exists.
- **Namespaces** drive live boot discovery — keep `namespaces` current.

The engine lives in `includes/Cert/` (`CertRunner` + `CertCommand`). The runner
is shared with Pro via `MVS_PRO_DIR`. The verdict is written to
`audit/cert-ledger.json`.

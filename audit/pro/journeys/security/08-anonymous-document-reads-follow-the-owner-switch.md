---
journey: anonymous-document-reads-follow-the-owner-switch
plugin: wpmediaverse-pro
priority: critical
roles: [anonymous, subscriber]
covers: [anonymous-document-reads]
prerequisites:
  - "Site reachable at $SITE_URL with Pro active and licensed"
  - "A bridge answering `mvs_document_drive_access` — this journey installs a stub"
estimated_runtime_minutes: 8
---

# A logged-out visitor can list a readable drive only when the owner allowed it

**Why this journey exists**: `read` used to be a level the ladder could hand out and the route would then refuse. A bridge could answer `mvs_document_drive_access` with `read` for a logged-out visitor on a public Space and `/documents` still answered 401 — so the `none`/`read` distinction was void below the sign-in line, and the two layers disagreed invisibly until a tab rendered empty.

2.4.0 resolves it against a decision the product had already made: `mvs_pro_documents_anon_links`, the owner setting for whether documents are reachable without signing in, **off by default**. This journey exists to keep two properties true at once — that the switch works, and that it never becomes "publish everything".

## Setup

```bash
cat > wp-content/mu-plugins/zz-journey-anon-docs.php <<'PHP'
<?php
// JOURNEY FIXTURE — remove after the run.
add_filter( 'mvs_document_drive_access', function ( $level, $type ) {
    return 'space' === $type ? 'read' : $level;   // a public Space
}, 10, 4 );
PHP
wp option update mvs_pro_documents_anon_links 0
```

## Steps

### 1. Switch OFF — refused, which is the default every site is on

- **Action**: signed out, `GET /wp-json/mvs-pro/v1/documents?drive=space:2`
- **Expect**: **401** `mvs_unauthorized`.
- **Why this is step one**: the change must be inert for every site that has not opted in. If this returns 200, an update has started serving documents to logged-out visitors on sites that never asked for it.

### 2. Switch ON — `read` finally means read

- **Action**: `wp option update mvs_pro_documents_anon_links 1`, then repeat the request signed out.
- **Expect**: **200**, listing the drive's documents.

### 3. The switch does not override the ladder

- **Action**: change the stub to return `none` for `space`, keep the switch on, repeat signed out.
- **Expect**: **not 200** — refused. Opting in to anonymous links must never hand out drives no bridge granted, or the setting silently means "publish everything".

### 4. Writing still needs a session

- **Action**: with the switch on and the ladder returning `own`, signed out:
  `POST /wp-json/mvs-pro/v1/documents/upload` with `drive=space:2`
- **Expect**: **401**. Eight write routes share the guard this change touched; this is the assertion that keeps it a read-only change.

### 5. Identity-scoped routes stay closed

- **Action**: signed out, with the switch on: `GET /wp-json/mvs-pro/v1/me/shared`, then `GET /wp-json/mvs-pro/v1/drives`
- **Expect**: **not 200** for both.
- **Why**: these share the listing callback and are NOT meant to be opened. They name no drive, so an anonymous caller resolves to `('user', 0)` and `drive_access()` refuses — protection by consequence rather than by intent, which is exactly why it is asserted here.

### 6. The delivery route agrees with the listing

- **Action**: with the switch on and the ladder granting `read`, request an actual document FILE anonymously.
- **Expect**: the same answer as the listing gave. A listing that says yes and a delivery that says no (or worse, the reverse) is the original disagreement in a new place.

## Teardown

```bash
rm wp-content/mu-plugins/zz-journey-anon-docs.php
wp option update mvs_pro_documents_anon_links 0
```

The option MUST go back to 0 — leaving it on grants logged-out access to every drive the bridge marks readable.

## Notes

Unit coverage: `tests/unit/DocumentAnonymousReadTest.php` (5 tests), mutation-tested both directions — refusing anonymous outright fails the "owner allowed it" case, and ignoring the switch fails the "switch is off" case. This journey adds what a unit test cannot: the delivery route agreeing with the listing, and the identity-scoped routes staying shut through a real dispatch.

Basecamp 10252332749.

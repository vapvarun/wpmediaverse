---
journey: usage-ledger
plugin: wpmediaverse
priority: high
roles: [member]
covers: [usage-ledger, mvs-transactions-wired]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=<login>)"
  - "A member who can upload media"
estimated_runtime_minutes: 4
---

# The usage ledger records every upload and is readable (regression sentinel)

**Why this journey exists**: `mvs_transactions` (Migrator v9, "Quota usage
transactions") shipped created-but-unused for several releases — a usage audit
trail that captured nothing. 1.8.0 wired it: `Services\TransactionService` records
a row on every `mvs_media_uploaded`, and `GET /mvs/v1/me/transactions` exposes the
member's history. This journey guards the write + the API so the ledger can't
silently go dead again (the "table created, never used" bug class).

## Setup

- Member A: autologin via `?autologin=A`.
- Baseline: `mysql_query "SELECT COUNT(*) FROM wp_mvs_transactions WHERE user_id = <A>"` — call it `$N0`.

## Steps

### 1. Uploading media appends a usage row
- **Action**: as A, upload one image via the upload block / `POST /mvs/v1/media`.
- **Expect**: `mysql_query "SELECT COUNT(*) FROM wp_mvs_transactions WHERE user_id = <A>"` returns `$N0 + 1`. The newest row has `media_type='image'`, `delta=1`, `reason='upload'`, and a non-negative `balance_after`.

### 2. The running balance increments
- **Action**: upload a second image.
- **Expect**: the newest row's `balance_after` is exactly the previous image row's `balance_after + 1` (the ledger keeps a running balance per media_type).

### 3. The REST API returns the member's history
- **Action**: `curl "$SITE_URL/wp-json/mvs/v1/me/transactions?per_page=10"` authenticated as A.
- **Expect**: HTTP 200, a JSON array newest-first containing the two upload rows with `{id, media_type, delta, balance_after, reason, created_at}`; the `X-WP-Total` header equals the member's total row count.

### 4. The API is gated
- **Action**: `curl` the same endpoint logged-out.
- **Expect**: HTTP 401 (not 404 — the route exists and is auth-gated).

## Pass criteria

ALL hold:
1. Each upload appends exactly one `mvs_transactions` row for the uploader with the right media_type / delta / reason.
2. `balance_after` runs as a per-type cumulative balance.
3. `GET /me/transactions` returns the member's history with correct pagination headers.
4. The endpoint is 401 for logged-out, never 404.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| No row appears after upload | TransactionService not booted / hook not attached | `includes/Core/Plugin.php` (register 'transactions' + boot at init); `includes/Services/TransactionService.php::init` |
| media_type empty / wrong | media_repository media_type read | `TransactionService::on_media_uploaded` |
| balance_after wrong | prior-balance lookup | `TransactionService::record` |
| /me/transactions 404 | controller not registered | `includes/Core/Plugin.php` (TransactionController in the controllers list); `includes/REST/Controller/TransactionController.php` |

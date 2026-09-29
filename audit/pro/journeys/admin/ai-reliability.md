---
journey: ai-reliability
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-AI-007, MV-AI-008, MV-AI-011, ai-circuit-breaker, ai-credentials, ai-manual-rerun]
prerequisites:
  - "Both plugins active; Google Vision and/or Rekognition configured with a key that will fail consistently"
  - "Action Scheduler available for the async re-run path"
estimated_runtime_minutes: 8
---

# The circuit breaker actually pauses a failing provider for exactly one hour, credential states are distinguishable in code but not in the UI, and manual re-run/reject work from the admin Media list

**Why this journey exists**: the circuit breaker is entirely invisible in
wp-admin — no "provider paused" indicator anywhere, only a PHP error log line
— so this journey is the only thing that actually proves it opens at exactly 5
consecutive failures, blocks for exactly 3600 seconds with no half-open probing,
and resets on any single success. It also documents, rather than silently
"fixes," a real observability gap: an owner cannot tell "blank key" from
"wrong key" from "circuit open" anywhere in the UI.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.
- A provider (Google Vision or Rekognition) configured with a key guaranteed to fail (revoked, wrong region, or a network block).

## Steps

### 1. Circuit opens on the 5th consecutive failure
- **Action**: trigger 5 consecutive failed calls to the same provider (5 uploads, or 5 direct `wp eval` calls into the provider's `call_api()`).
- **Expect**: on the 5th, the circuit "opens" — a transient (`mvs_circuit_open_<provider>`) is set for 3600 seconds, an error_log line is written, and the failure counter (`mvs_circuit_fails_<provider>`) is cleared.
- **On fail**: whichever AI provider class implements `call_api()`'s failure-counting.

### 2. A 6th call short-circuits with zero HTTP requests
- **Action**: attempt a 6th call immediately after.
- **Expect**: returns null immediately, with NO HTTP request made at all (confirm via a network-request check or a deliberately unreachable-but-monitored endpoint) — the provider is completely paused.

### 3. Cooldown is a hard timed reopen, no half-open probing
- **Action**: wait for the full 3600-second cooldown to elapse (or manually expire the transient for test speed), then attempt a call — including with a NOW-VALID key.
- **Expect**: the very next call after expiry goes through normally — no "half-open" trial-then-reopen logic exists, it's a hard timer.

### 4. Any single success resets the failure counter
- **Action**: with 4 consecutive failures recorded (one short of opening), make one successful call, then 4 more failures.
- **Expect**: the circuit does NOT open on the 4 new failures alone — only consecutive failures count, and the single success reset the counter to zero.
- **On fail**: failure-counter increment/reset logic — confirm it resets on ANY success, not just after a full cooldown.

### 5. Each provider has its own independent breaker
- **Action**: with Google Vision's breaker open, configure and call Rekognition (or Anthropic) with a working key.
- **Expect**: succeeds normally — `mvs_circuit_open_google_vision` being set has zero effect on `mvs_circuit_open_rekognition`/`..._anthropic`.

### 6. Blank vs. wrong key both resolve to "not configured," indistinguishably in the UI
- **Action**: (a) blank the Google key, select it as active, trigger a call. (b) blank only the AWS secret (access key present), select Rekognition, trigger a call. (c) blank the Anthropic key, select it, trigger a call.
- **Expect**: all three -> `mvs_no_ai_provider` "No AI provider is configured." — the provider was never registered. Confirm nowhere in the admin UI distinguishes this from any other failure.

### 7. Valid-format but revoked/wrong key registers, then fails at the HTTP layer
- **Action**: set a syntactically valid but revoked/wrong key for each provider in turn, trigger a call.
- **Expect**: the provider IS registered and `is_available()` returns true (key non-empty); the call proceeds and fails at the HTTP layer — for Vision/Rekognition this feeds the circuit breaker (see steps 1-5); for Anthropic it just fails that one call with no backoff/breaker.

### 8. No "Test Connection" exists for any AI provider — confirmed absence, not a gap to silently add
- **Action**: inspect the AI tab for all three Pro provider sections plus OpenAI.
- **Expect**: plain secret input fields with nothing attached to test them — the only "Test Connection" button anywhere in the admin belongs to the storage drivers (see `admin/storage-secrets-and-test.md`), not AI providers. Report this as the current, confirmed state.

### 9. Switching provider to a previously-removed key gives the same generic outcome
- **Action**: switch `mvs_ai_provider` to a provider whose key was previously removed.
- **Expect**: same `mvs_no_ai_provider` outcome, no "you selected X but never configured it" specific messaging.

### 10. Manual "AI re-run" — async when Action Scheduler is present
- **Action**: as an admin (or a `moderate_mvs_media` holder), select a media item with an existing AI result in the admin Media list, run "AI re-run."
- **Expect**: `ai_status` immediately flips to `processing`, then re-queued via Action Scheduler; notice reads "AI re-run queued for media #{id}. Reload this page in a moment to see the new result."

### 11. Manual "AI re-run" — synchronous fallback without Action Scheduler
- **Action**: on a host without Action Scheduler active, repeat the re-run.
- **Expect**: runs SYNCHRONOUSLY — the request visibly blocks until done; notice reads "AI re-run completed for media #{id}." (no "reload in a moment" framing, since it already finished).

### 12. Manual "AI reject" clears the result and timestamps the review
- **Action**: select a media item with an AI description/tags, run "AI reject."
- **Expect**: `ai_description` cleared, `ai_tags` cleared, `ai_status` set to `rejected`, `ai_reviewed` timestamped; notice: "AI result rejected for media #{id}. The AI description and tags were cleared."

### 13. Reject on an item with no existing AI result is idempotent
- **Action**: run "AI reject" on a media item that never had an AI result.
- **Expect**: still succeeds, no error — clears already-empty fields.

### 14. Capability and nonce gate both actions identically
- **Action**: as a user without `manage_options` OR `moderate_mvs_media`, attempt both bulk actions; then attempt with a missing/mismatched nonce.
- **Expect**: refused (capability check runs inline in `handle_bulk_actions()` for both `ai_reject` and `ai_rerun`); nonce mismatch rejected before either handler runs.

## Pass criteria

1. The circuit opens at exactly 5 consecutive failures, blocks for exactly 3600 seconds with zero outbound HTTP calls while open, and reopens hard (no half-open) after the cooldown.
2. Any single success resets the consecutive-failure counter; each provider's breaker is fully independent.
3. Blank and wrong-but-valid-format keys resolve to distinguishable code states (never-registered vs. registered-but-failing) that are NOT distinguishable anywhere in the UI — confirmed, not silently patched.
4. No "Test Connection" exists for any AI provider — confirmed absence.
5. Manual re-run is async with AS present, synchronous (blocking) without it; manual reject clears fields and timestamps, idempotently; both actions share one capability+nonce gate.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Circuit never opens after 5 failures | failure counter not persisted, or threshold constant changed | provider's `call_api()` failure counting |
| Circuit stays open past 3600s or reopens too early | transient TTL wrong | provider's circuit-open transient set |
| One provider's breaker affects another | transient keys not namespaced per provider | provider base class / breaker implementation |
| Manual re-run always runs synchronously even with AS present | Action Scheduler availability check missing/inverted | admin Media list bulk-action handler |
| Reject leaves stale `ai_description`/`ai_tags` | fields not actually cleared, only status flipped | admin Media list "AI reject" handler |

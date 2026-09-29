---
journey: license-management
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-LIC-001, MV-LIC-002, MV-LIC-003, MV-LIC-004, MV-LIC-005, MV-LIC-006, license-activation, license-update-channel]
prerequisites:
  - "A valid Pro license key (EDD item ID 1660828, store https://wbcomdesigns.com), and a known-expired/invalid key for the negative path"
  - "MVS_PRO_LICENSE_BYPASS confirmed undefined/false on this test site"
estimated_runtime_minutes: 8
---

# Activation/deactivation always update local state honestly; licence controls the update channel ONLY — nothing else, anywhere in the product

**Why this journey exists**: MV-LIC-004/005 are the plugin's most cross-cutting negative assertion — `License::is_valid()` must gate nothing but the Active/Inactive badge and the auto-update channel. `storage-drivers-cloud.md`, `legacy-platform-import.md`, and `flickr-connector.md` each exercise a feature that would be the natural place for a future regression to accidentally add a licence gate; this journey is where that absence gets asserted directly, plus the license screen's own activation lifecycle.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.

## Steps

### 1. Activate a valid key
- **Action**: Settings > License, enter the key, click "Activate License."
- **Expect**: POSTs `edd_action=activate_license` with `license`, `item_id=1660828`, `url=home_url()`, `environment`. On success, stores `wpmediaverse-pro_license_key` + `wpmediaverse-pro_license` options and `wpmediaverse-pro_license_key_allow_tracking` (`allowed: true`). Success notice: "License activated successfully." Status table shows: green "Active"; masked key (first/last 4 visible); tier label capitalized; Expires = formatted date, "Never. Lifetime License" for lifetime, or an em-dash if unknown.

### 2. Deactivate
- **Action**: click "Deactivate License."
- **Expect**: POSTs `edd_action=deactivate_license`, then UNCONDITIONALLY deletes both local options regardless of the remote response. "License deactivated." screen reverts to the activation form.
- **Edge case**: deactivating while the store is unreachable — local state still clears (by design); note this could double-consume an activation slot remotely if the deactivate call silently failed — a real risk to flag, not a defect to "fix" here.

### 3. Network failure reaching the store during activation
- **Action**: (if reproducible) block outbound requests to the store; attempt activation.
- **Expect**: "Could not reach the license server. Please try again." — distinct from a rejected key.

### 4. Every mapped error code shows its exact string
- **Action**: attempt activation with keys/conditions producing each of: expired, disabled, missing/invalid, site_inactive, item_name_mismatch, no_activations_left.
- **Expect**: `expired` -> "Your license has expired."; `disabled` -> "Your license has been disabled."; `missing`/`invalid` -> "Invalid license key." (both share this string); `site_inactive` -> "License is not active for this site."; `item_name_mismatch` -> "This license key is not for MediaVerse Pro."; `no_activations_left` -> "No activations remaining for this license."; any unmapped code -> "An error occurred. Please try again."

### 5. Client-side expiry re-derivation
- **Action**: with a key stored as `license: 'valid'` whose `expires` date has since passed (no fresh API re-check performed), reload the License tab.
- **Expect**: shows Inactive/the activation form on that basis — `is_valid()` re-derives expiry client-side from the stored `expires` date, not just the stored status string.

### 6. Empty key round-trips, no distinct "enter a key" prompt
- **Action**: submit Activate with an empty key field.
- **Expect**: no client- or server-side blank-key check — it posts `license: ''` to the store and gets back the same generic "Invalid license key." as any other bad key.

### 7. Update channel gating — the one thing licence state controls
- **Action**: with the license inactive/expired, check Plugins/Updates for an available WPMediaVerse Pro update. Activate the license. Re-check.
- **Expect**: inactive/expired blocks receiving the update notification/download via EDD SL; nothing else changes.

### 8. Prove the negative across every feature area touched this cycle
- **Action**: with the license inactive (and `MVS_PRO_LICENSE_BYPASS` undefined), end-to-end: configure and use a cloud storage driver (upload, migrate, Test Connection — see `storage-drivers-cloud.md`); run a CLI import (`legacy-platform-import.md`); connect and fully use the Flickr connector (OAuth, import, export, sync — `flickr-connector.md`).
- **Expect**: every one of these works identically to a licensed site — this is the explicit, exception-free rule (the ONE stated exception is Documents module WRITES, covered separately in `documents-drive-basics.md`). No feature here shows a license-gated notice, blocked button, or "upgrade to unlock" state. Any such gate found is a direct contradiction of the documented design and a high-priority defect.
- **On fail**: whichever surface added the gate — this is exactly the "gating a second feature behind this precedent" risk the CLAUDE.md warns against.

### 9. License tab at 390px, both states
- **Action**: load the License tab at 390px in the activation-form state and the active-status-table state.
- **Expect**: the `form-table` status rows and the key-entry form don't overflow; the masked-key `<code>` element doesn't force horizontal scroll; Activate/Deactivate buttons remain full-width-tappable.

## Pass criteria

1. Activation/deactivation each match their exact documented network calls, stored options, and notice text — deactivation always clears local state even offline.
2. Every mapped EDD error code shows its exact string; unmapped codes get the generic fallback; expiry is re-derived client-side even from a stale "valid" status.
3. Licence state gates ONLY the update channel — every non-Documents-write Pro feature works identically whether licensed or not.
4. The License tab renders correctly at 390px in both states.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A storage driver, importer, or connector shows a license-gated notice | a regression added a licence check outside Documents writes | that feature's permission/gate check |
| Deactivation doesn't clear local state on a failed remote call | deactivate handler branching on the remote response instead of unconditional delete | `License` deactivate handler |
| Stale expired key still shows "Active" | `is_valid()` not re-deriving expiry from the stored `expires` date | `License::is_valid()` |
| Wrong error string for a mapped EDD code | `get_license_error_message()` map entry missing/wrong | `License::get_license_error_message()` |

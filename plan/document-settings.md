# Document settings — plan

> **STATUS (2026-08-10): planned, not built.** Written after reading the shipped
> settings code, not from the backlog wording. Supersedes the settings row of
> `document-library.md` §11, which specified six settings fields. This plan ships
> **one field and four filters**, on a plug-and-play rule stated in §4.

**Read `RESUME-document-library.md` first** for the feature's state. This file
covers one question only: **what a site owner can configure about documents, and
who is allowed to use them.**

---

## Why this exists

Documents shipped with **no owner controls whatsoever**. Verified against the
code on 2026-08-10:

- `ProSettings.php` registers **26 settings, none of them for documents**.
- `Pro\Core\Plugin.php:145` hardcodes `add_filter( 'mvs_documents_enabled', '__return_true' )`.
  Documents are **force-on the moment Pro activates**, and there is no way to
  turn them off.
- Default privacy is hardcoded `'private'` at `DocumentIngestService.php:251`.
- The allowed type list is code-only (`DocumentTypes::ALL`, 11 types).
- Max size falls through to `mvs_max_upload_size` (`AppConfig.php:91`) with no
  document-specific value.
- Anonymous share links can be minted and redeemed **with no owner switch and
  no rate limiting** (`PermissionController.php:94`; zero `RateLimiter` calls
  exist anywhere in Pro).
- `manage_mvs_documents` exists as a constant, is **granted to no role**, and
  gates nothing — both the admin screen and the drive-admin ladder actually run
  on `manage_options`.

That last group is the reason this is not cosmetic. **An owner cannot currently
prevent their members from publishing anonymous links to private documents.**

---

## The rule this plan is built to satisfy

> **Documents are configured the way media is configured, and a normal site
> configures nothing.** Same panel, same feature-toggle pattern, same permissions
> matrix, symmetrical filter names. An owner who has already set up media should
> recognise the one control there is, and need no others.

Where documents genuinely differ from media, they differ **once**, and the
difference is written down here. Everywhere else they reuse.

---

## 1. Where it lives

A **Documents tab in the existing Settings panel**, registered **from Pro**.

`Admin\Settings\SettingsPage::get_registered_sections()` already ends with
`apply_filters( 'mvs_settings_sections', $sections )` (`SettingsPage.php:331`).
That is the seam; it needs no change. Pro adds one entry:

| Key | Value |
|---|---|
| `group` | `media` |
| `label` | Documents |
| `icon` | `file-text` |
| `description` | Turn the document drive on or off. Limits and sharing are filter-tunable. |
| `option_group` | `<OPTION_GROUP>_documents` |
| `page_slug` | `<PAGE_SLUG>-documents` |
| `section_ids` | `mvs_documents` |
| `is_pro` | `true` |
| `priority` | after Display, before Storage |

**Free gains no document settings code.** On a Free-only site the tab does not
exist, which is correct: the feature does not either.

---

## 2. The default posture — member-friendly, and already correct

**Owner directive (2026-08-10): the shipped default must be friendly to ordinary
members. A subscriber can create folders and upload files, out of the box.**

The good news is that this is **already how it works**, and it is deliberate.
Verified in the code:

| Action | What actually guards it today |
|---|---|
| Upload a document | `current_user_can( 'upload_mvs_media' )` — `REST\DocumentController.php:347` |
| Create a folder | Logged in **and** owns the drive — `REST\FolderController.php:156-166` |
| Use the drive at all | The section's own gate; no extra capability |

`upload_mvs_media` is granted to **every role including subscriber**, via
`MediaCapabilities::get_base_member_caps()`, for a reason already written down:

> MediaVerse is a community/social platform, NOT a blog/CMS: any logged-in
> member uploads media and it publishes immediately.

So a subscriber can already create folders and upload documents on a default
install. **This plan must not change that**, and the settings below are written
so that a site which touches nothing keeps exactly today's behaviour.

## 3. Who can use it — ONE new capability, not two

An earlier draft of this plan proposed a new `upload_mvs_documents` capability.
**That was wrong and is dropped.** The reasoning is recorded because it is the
kind of change that looks tidy and is actively harmful:

1. **It would lock members out on upgrade.** A new capability is granted by
   `add_caps()` on a version bump. Any site where that does not run — or runs
   late — has every subscriber silently lose their drive, having had it the day
   before.
2. **It reintroduces the "second door" the code already guards against.**
   `DocumentController.php:344-346` says it plainly: *"The same capability that
   gates media upload. A site that has taken upload rights away from a role must
   not hand them back through a second door."* A separate document-upload cap is
   precisely that second door — an owner who revoked media upload from a role
   would find documents still open.
3. **It duplicates a control the matrix already has.** "Can this member upload"
   is one question with one answer.

So documents keep using `upload_mvs_media`. The matrix gains **one** column:

| Capability | Matrix column | Meaning | Default |
|---|---|---|---|
| `manage_mvs_documents` | **Manage Documents** | Administers *everyone's* documents — the wp-admin screen and `is_drive_admin()` in the permission ladder | **administrator only** |

This one is genuinely missing: it exists as a constant (`PermissionService.php:66`),
is granted to **no role**, and gates nothing — both the admin screen and the
drive-admin path actually run on `manage_options`. Making it real is what lets an
owner delegate document administration without handing over the whole site.

It is **administrator only** by default. Granting it to editor would hand every
editor the entire membership's private documents on upgrade, silently. An owner
who wants that does it in the matrix, deliberately — which is the whole point of
making the capability real.

### Why not mirror media's other caps

Media has `edit_mvs_media`, `edit_others_mvs_media`, `delete_mvs_media`,
`delete_others_mvs_media`. Documents must **not** copy them: for documents those
questions are already answered by **ownership and grants** in `PermissionService`
under D1. A column saying "Edit Others" would create a second authority that can
disagree with the grant ladder, with no rule for which wins. One authority.

### The one change Free needs

`PermissionsManager::get_managed_caps()` (`PermissionsManager.php:64`) is a
**private method with a hardcoded list**, so Pro cannot add a column to the
matrix. It needs the same treatment `mvs_settings_sections` already has:

```php
return apply_filters( 'mvs_managed_caps', $caps );
```

One line, one new filter, mirrors an existing pattern. The matrix already
enumerates **every** site role via `wp_roles()->get_names()`, so the new column
appears for custom, BuddyPress and WooCommerce roles with no further work.

---

## 4. Plug and play — one switch in the UI, everything else a filter

**Owner directive (2026-08-10): plug and play. A setting a normal site does not
touch on a regular basis should be a filter, not a field. And media and documents
are one plugin — they should work uniformly.**

Those two pull in slightly different directions, so the resolution is stated
plainly rather than fudged.

### The test, applied to both features

> **A control earns a field only if a normal site changes it. Everything else
> gets a filter with a sensible default.**

Applied honestly, almost nothing about documents needs a field. The defaults are
already the right answer for a normal site: every type allowed, the server's own
size limit, private by default, anonymous links off.

### What ships in the UI: one toggle

| Option | Shape | Default |
|---|---|---|
| `mvs_pro_documents_enabled` | Master **Enable Documents** checkbox | **on** |

This matches Pro's existing feature pattern exactly — `mvs_connectors_enabled`
(`ProSettings.php:284`), `mvs_pro_transcode_enabled` (`:1505`),
`mvs_watermark_enabled` (`:1033`) are all a master toggle with the feature's
section beneath. Documents becomes one more of those, so an owner finds it where
they already look for "is this feature on".

It is the one control that genuinely needs a field: "I do not want documents on
my site" is a real, one-time decision, and hunting for a filter to express it
would be hostile.

### What ships as a filter

Each has the default a normal site wants, so an untouched install needs nothing.

| Filter | Default | Replaces |
|---|---|---|
| `mvs_document_max_size` | `wp_max_upload_size()` — the server's own | the current read of **media's** limit |
| `mvs_document_allowed_types` | all 11 in `DocumentTypes::ALL` | a code-only constant |
| `mvs_document_default_privacy` | `private` | a hardcoded string at ingest |
| `mvs_document_anon_links` | `false` | nothing — closes the D5 exposure |

A filter is the better shape for `_anon_links` specifically: defaulting off means
the exposure is closed everywhere, and turning it on takes a deliberate line of
code rather than a checkbox somebody ticks while exploring.

### Uniformity — the same layer for media

Media already exposes three of these as **fields** (`mvs_max_upload_size`,
`mvs_allowed_file_types`, `mvs_default_privacy`). Under the test above, those
fields would not be added today — but they are shipped, on 50+ live sites, and
**removing a setting owners already use is a breaking change** (Production Rule
2). They stay.

Uniformity is achieved the additive way instead:

- **Media gains the matching filters**, wrapping its existing option reads —
  `mvs_media_max_size`, `mvs_media_allowed_types`, `mvs_media_default_privacy`.
  The option still wins where an owner has set one; the filter is the code-level
  override that documents have from day one.
- **Naming is symmetrical**, so a developer who learns one guesses the other:
  `mvs_document_*` and `mvs_media_*`, same suffixes, same argument order.
- **Promotion is cheap and one-directional.** If owners ask for a document
  size field, it is one `add_settings_field()` call against an option that
  already exists behind the filter. Starting with a field and later removing it
  is the move that cannot be undone.

So: media keeps its fields, both features gain a uniform filter layer, and
documents ship with one switch. Nothing is removed and nothing is duplicated.

### Types and size: the platform decides the ceiling

**Owner directive (2026-08-10): types follow what WordPress allows and size
follows what the server allows. By default every document type is permitted and
the limit is the server's own. A site that wants to narrow either does so through
the filter.**

Both are **restrictions, never extensions**. Neither the filter nor any future
field can raise a limit the platform imposes — a value above the server maximum
is clamped rather than accepted and silently ignored by PHP.

#### Size

| | Value |
|---|---|
| Stored default | `0` |
| Rendered as | "Server maximum (300 MB)" — the real `wp_max_upload_size()`, not a hardcoded figure |
| Admin sets | any value **below** the server maximum |
| Above the maximum | clamped on save, with the ceiling named in the notice |

Measured on the dev site: `wp_max_upload_size()` = **300 MB**, while
`mvs_max_upload_size` (media) = **100 MB**. So documents defaulting to the server
maximum means **documents may be larger than photos by default**. That is
intended — a contract or a deck is legitimately bigger than an avatar — but it is
a real difference from media and is recorded here so nobody "fixes" it later.

#### Types — and the markdown problem

The admin's list is the product's own vocabulary, the 11 in `DocumentTypes::ALL`,
all ticked by default. But "based on what WordPress allows" needs one thing
resolved, because taking it literally today would **remove a working feature**:

- Measured: WordPress's default `get_allowed_mime_types()` covers **10 of the 11**
  — pdf, word, excel, powerpoint, odf_text, odf_sheet, odf_presentation, text,
  csv, rtf.
- **Markdown is not in it.** WordPress has no `.md` mime at all.
- Markdown uploads work today *anyway*, because document ingest does not gate on
  WordPress's list: `DocumentTypes::resolve()` dispatches on **extension**
  (`DocumentTypes.php:185`), and `wp_check_filetype_and_ext()`
  (`DocumentIngestService.php:343`) is consulted for the sniff, not as a veto.
  There is a `.md` file in the drive right now.

Gating documents on `get_allowed_mime_types()` as-is would therefore **break
markdown upload** — a regression dressed as a policy fix.

**DECIDED (owner, 2026-08-10): register `.md` through `upload_mimes`.** Markdown
is a mainstream document format now, and the fix is the honest direction of
travel — **make WordPress agree with the plugin, rather than have the plugin
quietly bypass WordPress.** Nothing in either plugin hooks `upload_mimes` today.
Once it does, `get_allowed_mime_types()` genuinely contains every type the
document library accepts. Then:

- "Types follow what WordPress allows" becomes **true**, not approximately true.
- The admin's tick-list narrows from a list WordPress itself agrees with.
- Unticking a type removes it from both the plugin's allowlist *and* the drive's
  filter chips, which are already built from what is present rather than the full
  vocabulary.

The sanitizer still rejects anything outside `DocumentTypes::ALL`. That list is a
security boundary — an admin cannot tick their way to arbitrary uploads, and the
`.zip`-renamed-`.docx` content checks are unaffected.

### Notes that matter

**`_enabled` must default ON for existing installs.** A naive default-off would
remove the Documents section from every site that already has documents in it —
data still there, feature gone, looks like a regression. The option is registered
with default `1`, and an absent option reads as on. Turning documents off hides
the section and the admin screen; it does **not** delete anything.

**Size defaults to the SERVER's maximum, not media's.** An earlier draft had it
inherit `mvs_max_upload_size`; that is exactly the duplicate-that-fights §5
forbids, and it is wrong on the product point too — media and documents are
different elements, and a contract is legitimately bigger than an avatar. The
filter's default is `wp_max_upload_size()`, and a larger returned value is
clamped to it.

**The type list is a security boundary, not a preference.** Whatever the filter
returns is intersected with `DocumentTypes::ALL` before use. A site cannot filter
its way to arbitrary uploads, and the `.zip`-renamed-`.docx` content checks are
unaffected.

**Anonymous links default OFF, and that is a deliberate behaviour change.** They
currently work with no switch and no rate limiting, which violates locked
decision D5 ("rate limiting ships with anonymous links, or anonymous links do not
ship"). Defaulting off closes the exposure on every site without removing the
feature. **It does not substitute for the rate limiter** — D5 still requires that
before anonymous links are on for anybody. See "Sequencing" below.

---

## 5. No duplicate settings — media and documents are different elements

**Owner directive (2026-08-10): do not create settings that fight each other.
Media file types and document file types are different elements.**

Two rules follow, and one existing collision has to be cleaned up rather than
added to.

### Rule 1 — a document setting REPLACES the media read, never sits beside it

Where a document setting is introduced, every place the document code currently
reads the media option must switch to it in the **same change**. Leaving both
live is what "fighting" means in practice: two owners, two numbers, and the
narrower one wins for reasons nobody can see from either settings screen.

**There is exactly one such collision today, and it is size.** Document upload
reads **media's** limit in two places:

| Call site | What it does | Must become |
|---|---|---|
| `DocumentIngestService.php:171` | `SettingsHelper::get_max_upload_size( $user_id )` — **enforces** the limit | the document limit |
| `AppConfig.php:88` `effective_max_size()` | what the **app is told** the limit is | the document limit |

Both, or neither. Changing only the enforcement leaves the mobile app advertising
a limit the server rejects; changing only `AppConfig` leaves the app honest and
the server wrong.

Note `get_max_upload_size()` takes `$user_id` — it is **user-aware**, so a
per-role or quota-driven limit resolves through it. The document equivalent must
keep that awareness rather than flattening to a single global number, or sites
using per-role limits lose them for documents only.

### Rule 2 — where there is no collision, keep them separate

Verified by grep across `includes/Documents`, `DocumentController` and
`FolderController`:

| Media setting | Read by document code? | Verdict |
|---|---|---|
| `mvs_allowed_file_types` | **no** | Genuinely different elements. Media's list is jpg/mp4/mp3; documents have their own 11-type vocabulary in `DocumentTypes`. Two lists, no overlap, no conflict. Correct as-is. |
| `mvs_default_privacy` | **no** | Documents hardcode `private` at ingest; media defaults `public`. Different answers on purpose — a document is private until shared, a photo is posted. Keep them independent. |
| `mvs_max_upload_size` | **YES, twice** | The one collision. See Rule 1. |

So of the three, only size needs untangling. Types and privacy are already
separate and must **stay** separate — a shared "allowed types" control would be
the duplicate-that-fights, since ticking `.mp4` has no meaning for a document
drive and unticking `.pdf` would have no meaning for media.

---

## 6. What this does not add, and why

`document-library.md` §11 specified six settings. Three are dropped and one
deferred, each for a reason:

| Not added | Why |
|---|---|
| `_link_ttl` | An owner should not be choosing token lifetimes in seconds. Ship a sane fixed expiry with the anonymous-link feature; add the setting only if a customer asks for a different one. |
| `_cloud_enabled` / `_bucket` | **Document cloud storage does not exist.** `StorageResolver::relative_path()` and `readable_path()` resolve under `wp_upload_dir()` unconditionally; `cloud_enabled()` is read by nothing but the Site Health check, and `validate_bucket()` has no production caller. A setting here would promise a capability that silently does nothing. **Deferred until the storage routing exists** — at which point it is one field, not a redesign. |
| Per-role quotas | D2 settled this: documents count against the uploader's existing media quota. A second counter is the thing D2 exists to prevent. |
| Retention / scan toggles | Configuration for machinery that is not built (`mvs_document_scan_file` and `scan_status` do not exist). Build the machinery, then decide if it needs a switch. |

---

## 7. Build order

1. **Free** — make `get_managed_caps()` filterable. One line. Ship-safe on its
   own; changes nothing until something hooks it.
2. **Pro** — grant `manage_mvs_documents` to administrator on the existing
   `add_caps()` version-bump path, and hook `mvs_managed_caps` to add the one
   column. **No new upload capability** — documents keep using
   `upload_mvs_media`, so nothing a member can do today changes.
3. **Pro** — register `.md` on `upload_mimes` so WordPress's allowed list matches
   the document library's. Independent of the settings; ship it first if
   convenient, since it only ever *adds* a type.
4. **Pro** — add the Documents section via `mvs_settings_sections` with the
   **single** Enable Documents toggle, matching `mvs_connectors_enabled`.
5. **Pro** — replace `add_filter( 'mvs_documents_enabled', '__return_true' )`
   with the real option, reading absent-as-on.
6. **Pro** — introduce the four filters at their existing hardcoded points, and
   in the same change **remove the media reads they replace** (§5 Rule 1):
   - ingest privacy — `DocumentIngestService.php:251`
   - **size, BOTH call sites** — `DocumentIngestService.php:171` (enforcement)
     and `AppConfig.php:88` (what the app is told). Both, or neither.
   - type allowlist — the `DocumentTypes` callers
   - anonymous-link gate — `PermissionController.php:274`
7. **Free** — add the three matching `mvs_media_*` filters around media's
   existing option reads. Purely additive: the option still wins where set, so
   no shipped site changes behaviour. This is what makes the two features
   uniform without removing any field owners already use.

Steps 1–5 and 7 are additive and safe. **Step 6 changes behaviour** — it moves
documents off media's size limit — and is where the verification below bites.

### Sequencing against D5

Defaulting `_anon_links` off reduces the exposure but **does not satisfy D5**.
Either the rate limiter lands in the same release, or the link-mint route comes
out of the v1 cut. This plan assumes the rate limiter lands; if it does not, drop
the setting and the route together rather than shipping a switch that guards an
unthrottled endpoint.

---

## 8. Verification (blocking)

Per-item, in a browser, as a **member** and as an **owner** — not batched.

- **An upgrading site keeps its documents.** Activate this build over 2.4.0 with
  documents already present: the section is still there, the drive still lists,
  no setting was touched.
- **Turning documents off** hides the dashboard section, the admin screen and the
  REST routes, and **deletes nothing** — turning it back on restores the drive
  intact.
- **A SUBSCRIBER on a default install can create a folder and upload a file.**
  This is the directive the whole plan is built around, and it is the one a
  refactor is most likely to break. Walk it as a real subscriber, not as an
  admin — admin passes guards a member does not.
- **Revoking `upload_mvs_media` from a role stops document upload too**, through
  the one door. Confirms no second door was opened.
- **A role granted `manage_mvs_documents`** but not `manage_options` can open the
  admin Documents screen. This is the delegation the capability exists for and it
  is the one most likely to be missed, because `manage_options` currently masks it.
- **Default privacy** set to Members: a new upload lands `members`, and an
  existing private document is **not** retro-changed.
- **Untouched install: the server's limit applies, not media's.** With no filter
  hooked, set media to 10 MB and upload a 50 MB document — it must **succeed**,
  because documents now follow `wp_max_upload_size()`. Today it fails. This is
  the single clearest proof the two settings stopped fighting.
- **Both halves move together** (§5 Rule 1). Hook `mvs_document_max_size` to a
  smaller value: a larger file is refused **with the document limit in the
  message**, and `/app/config` reports the same number. A mismatch between what
  is enforced and what the app is told is the exact failure Rule 1 exists to
  prevent.
- **A filter above the server maximum is clamped**, not accepted and silently
  ignored by PHP.
- **Markdown still uploads** after the `upload_mimes` change — and `.md` now
  appears in `get_allowed_mime_types()`, so WordPress and the plugin agree rather
  than the plugin bypassing it.
- **Allowed types**: with no filter hooked, all 11 upload. Filter Excel out and
  `.xlsx` fails with a named reason, and the type disappears from the drive's
  filter chips. Returning junk from the filter must not widen anything — the
  intersection with `DocumentTypes::ALL` still holds.
- **Anonymous links are off by default**: the mint route returns the D4 error on
  an untouched install, and an already-issued link stops resolving. Confirm the
  second half — closing new links while old ones keep working is not what "off"
  means.
- **390px and dark mode** on the Documents section, per the standing rule. One
  toggle is a small surface, which is rather the point.

---

## 9. What this deliberately leaves for later

- The rate limiter (D5) — separate work, but blocking for anonymous links.
- Cloud storage routing + its two settings (D8).
- `mvs_document_scan_file` and `scan_status` (§11's "the gap a security reviewer
  finds first").
- Metadata stripping on ingest.

None of these are settings problems. Listing them here so nobody reads this
plan's completion as the feature being fully governed.

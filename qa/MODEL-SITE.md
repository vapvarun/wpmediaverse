# The model site — `mediaverse.local`

**What it is:** the QA baseline. The install every smoke run, journey run and cert run is
executed against, in a state somebody else can reproduce and recognise.

**What it is NOT:** a demo site. Roughly a quarter of its documents are QA fixtures with names
like `PRIVATE Tax Records uid22`, `PW Folder 1786435516301` and `Real ODS`. **They stay.** The
journeys depend on them, the album injection fixture has already decayed twice and been rebuilt,
and a journey whose Setup has rotted passes while proving nothing — which is the single most
expensive failure mode this QA suite has. Screenshots and marketing shots belong on a different
install; do not tidy this one for them.

Established 2026-08-19. Update this file whenever the baseline genuinely changes — a drifted
description is worse than none, because the next person diffs against it.

---

## 1. Versions

| | |
|---|---|
| WordPress | 7.0.4 |
| PHP | 8.2.29 (Local's bundled runtime) |
| WPMediaVerse | 2.4.0 (branch `2.4.0`, untagged) |
| WPMediaVerse Pro | 2.4.0 (branch `2.4.0`, untagged) |
| Also active | BuddyPress, BuddyNext |
| Schema | `mvs_db_version` = **29** |
| LibreOffice | `/Applications/LibreOffice.app/Contents/MacOS/soffice` |

**Both plugins are active, and that is the `combo` configuration.** The `free` smoke mode
deactivates Pro; put it back afterwards or every later run is measuring the wrong site.

**LibreOffice being installed here is a property of this machine, not of the product.** Most
shared hosting does not have it. It means document previews render fully here and the
"this host cannot convert" path is NOT exercised by default — test that path by pointing
`mvs_pro_soffice_binary` at a nonexistent file, never by uninstalling LibreOffice.

## 2. Licence: ACTIVE, deliberately

`wpmediaverse-pro_license` holds a valid lifetime object. This matters more than it used to.

Since 2026-08-19 the Pro licence gates document WRITES (`Documents\DocumentLicense`, Pro
CLAUDE.md §7). An unlicensed site is read-only for members: no upload, no folder create, no
rename, no share. **A QA run against an unlicensed site would report a broken drive and be
right.** A paying customer's site is licensed, so that is what the baseline models.

Restore it with:

```
wp eval 'update_option( "wpmediaverse-pro_license", (object) array( "license" => "valid", "expires" => "lifetime" ) );'
```

To test the *lapsed* behaviour, `wp option delete wpmediaverse-pro_license`, then put it back.
Do not leave it deleted.

## 3. Settings that define the baseline

| Option | Value | Why this value |
|---|---|---|
| `mvs_storage_driver` | `bunnycdn` | Exercises the cloud path. A local-driver baseline would never catch the CDN-variant bugs that shipped in 2.3.1 |
| `mvs_pro_documents_enabled` | `1` | |
| `mvs_pro_documents_max_size` | `0` | Follow the server |
| `mvs_pro_documents_allowed_types` | absent | Absent means every type — different from empty, which means accept nothing |
| `mvs_pro_documents_default_privacy` | `private` | |
| `mvs_pro_documents_anon_links` | `1` | On, so anonymous-link journeys can run |
| `mvs_pro_documents_extraction` | `1` | |
| `mvs_default_privacy` | `public` | Media, not documents — the two are deliberately different |
| `mvs_drive_backfill_cursor` | `-1` | **Means finished.** Absent or `0` means still running, and `drive_documents()` emits its legacy `post_author` branch instead of the index-shaped query |

## 4. Data shape

| | Count |
|---|---|
| Users | 28 |
| Documents (publish) | 121 |
| Documents (trash) | 3 — the trash/restore surfaces need something in the bin |
| Images | 70 |
| Video | 3 |
| Audio | 1 |
| `legacy_document` | **1 — keep it** |
| Folders | 56 |
| Access grants | 55 |
| Document search rows | 120 |

**The single `legacy_document` row is load-bearing.** It is the pre-1.2.3 MIME catch-all that
Migrator v27's quarantine targets, and it is the only reason the 2026-08-15 query-parity check
caught `MediaTypes::ALL` being used to mean "no type filter" — a constant that silently omits
`legacy_document`. Deleting it removes the only live specimen of a bug class.

**Known gap:** no active tournament fixture exists, only a finalised one, so active-tournament
rows have never been walked. Not seeded, per the no-self-seeding guardrail.

## 5. Health, as of 2026-08-19

- `wp mvs cert` — **69 pass / 0 fail / 0 hole**
- `wp mvs-pro cert` — **59 pass / 0 fail / 0 hole**
- Every plugin surface answers 200: `/`, `/explore-media/`, `/explore-document/`, `/upload-media/`,
  `/compete/`, `/my-media/`, `/my-media/documents/`, `/my-media/albums/`, `/my-media/profile/`
- `wp-content/debug.log` — **zero plugin-origin entries** after loading all of the above

**Slugs are singular:** `explore-media` and `explore-document`. `/explore/` and
`/explore-documents/` do not exist; WordPress answers the first with a 301 guess to
`/explore-document/`, which looks like a redirect bug and is not one.

**The only noise in `debug.log` is WP-CLI's own** `php-cli-tools/Colors.php` deprecation, one
line per coloured output on PHP 8.5. Filter it out before reading:
`grep -v "wp-cli\|php-cli-tools" wp-content/debug.log`.

## 6. Restoring the baseline after a run

Anything that seeds must clean up after itself. Two that do:

```
wp mvs seed-documents --cleanup          # removes only rows carrying _mvs_seeded_fixture
wp option delete wpmediaverse-pro_license && wp eval '…'   # see §2
```

After any run, confirm nothing was left behind:

```sql
SELECT 'seeded'      k, COUNT(*) v FROM wp_mvs_media_meta   WHERE meta_key = '_mvs_seeded_fixture'
UNION ALL SELECT 'cert users',  COUNT(*) FROM wp_users      WHERE user_login LIKE 'mvs-cert-%'
UNION ALL SELECT 'orphan docs', COUNT(*) FROM wp_mvs_media_index m
  WHERE m.media_type = 'document' AND m.drive_type = 'user'
    AND m.drive_id NOT IN ( SELECT ID FROM wp_users );
```

All three must be `0`. They were on 2026-08-19, after a 30,000-document scale fixture and two
cert runs — which is the point: **a QA tool that mutates the site it measures turns the next run's
baseline into a guess.** The cert oracle had to be rewritten for exactly this reason; it was
creating a real folder on every pass, including one orphaned on a deleted throwaway user's drive.

## 7. WP-CLI on Local — read this before debugging a phantom

A bare `wp` outside Local resolves `DB_HOST=localhost` to the wrong MySQL, so it reads a
**different site's database** and reports data that is not there. Always go through the
`mcp-local-wp` `wp_cli` tool, which pins the command to this site's own socket.

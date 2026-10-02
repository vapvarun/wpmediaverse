# 2.6.1 handoff

Branch `2.6.1` in both repos (cut from main after the 2.6.0 release). Every card
on the WPMediaVerse board carries a 2.6.1 planning comment with its verdict;
the cards are the source of truth, this file is the index.

## Done (in Ready for Testing, browser-verified, pushed)

| Card | Fix | Commit |
|---|---|---|
| 10360879667 | Album/collection lists (/album/, /collection/, BuddyPress tab, mvs/v1) use `PrivacyService::query_listable_spaces()`; core wp/v2 routes for both CPTs off | Free 83f35178 |
| 10355752606 | `MediaRepository::media_ids_only()` refuses only album/collection IDs with no media row | Free d52fc2ac |
| 10355658865 | `FolderService::dequeue()` cancels the purge job on permanent delete and restore | Pro 9be6a4d |
| 10355536157 | `MVS_MIN_PRO` 2.6.0, pinned by BootGuardTest | Free 2c453e00 |
| 10355538303 | `build/blocks/blocks/` out of the zip (+ build-release check), Lock Overlay CSS removed | Free 50c52a4d |
| 10355656744 | Dead Quota Page rule removed | Pro 2ea7272 |
| 10354828394 | WP password/private hidden and neutralised for albums; album type select | Free 6568a930 |
| 10350226727 | Product links to wbcomdesigns.com/downloads/ | Free a95272ac, Pro 97d9529 |
| 10073529518 (code part) | .pot bug address = wbcomdesigns.com/support/ | same |
| 10335795450 (tag item) | Tag cloud counts public items only | Free 811037b7 |

## Features done (Ready for Testing)

| Card | Change | Commit |
|---|---|---|
| 9941211270 | Load More auto-loads 3 pages, then the button (shared load-more.js lists; My Media dashboard unchanged) | Free 15f3bbfc |
| 10124085450 | Upload modal 'Matching tags' pills, focus returns to the field | Free b293ba79 |
| 9822979354 | One alt (`TemplateHelpers::alt_text()`) for grid, lightbox (REST `alt`) and JS cards | Free bbbd5de0 |
| 10252887530 | Empty states already had adjacent actions; Compete copy fixed | Pro df20308 |
| 9765027990 | new_follower + media_comment emails (owner switches, off by default) | Free aeb843b8 |
| 9937716241 | Trending via cached `ranked_feed_page()` in `query()`; Type filter on feeds | Free 1cac4365 |

## Owner decisions (2026-10-02), done

- On BuddyNext sites BuddyNext sends MediaVerse activity emails; MediaVerse emails only standalone (`EmailService::host_sends()`, Free 5a5e734c). BuddyNext gaps filed: BN card 10364576620.
- Visitors see only tags on public items in GET /mvs/v1/tags; members keep the full list; /wp/v2/mvs_tag off (Free 334a093a).

## Friction walk (2026-10-02), fixed (Ready for Testing)

| Card | Change | Commit |
|---|---|---|
| 10364776039 | Duplicate check scoped to the uploader (`find_by_hash( $hash, $author )`), no foreign ID | Free 2dfaa272 |
| 10364776646 | `TemplateHelpers::fixed_privacy_note()` on 3 upload surfaces; help text follows the setting | Free f6bcf987 |
| 10364777243 | Settings saved notice at the top of the tab, scrolled into view on phones | Free e9acfd93 |
| 10364776932 | Lightbox Save shows Saved (Favorites); picker emits `mvs-collections-changed` | Free f97c01f1, Pro cae177e |
| 10364777491 | Upload page shows the picked file + thumbnail | Free 5699d45e |
| 10364777801 | My Media at 390: compact profile row, idle dropzone hidden beside the FAB, one-line streak | Free ca449b4f, Pro 35bf0b8 |
| 10364778286 | On BuddyNext sites the Edit profile section and banner step aside; starting tab via `DashboardSections::resolve()` | Free 9fdcd353 |
| 10364776389 | Album prompts name photos; stricter album privacy asks first | Free 73df8a04 |

Waiting on BuddyNext: Report opener on `#report` (BN 10364838835; MV card 10364778043 in Not now), signup terms checkbox (BN 10364779324), onboarding, bottom-bar +, profile Media drop zone, delete mirrors, battle/document emails + bell (BN 10364576620). Gamification toasts (WP Gamification 10364780414), Reign logo (Reign 10364780720).
Polish left in Bugs: Documents drive (10364778512), lightbox/visitor polish (10364778707), admin polish (10364778984).

## Gotchas

- Run PHPUnit with `WP_TESTS_DIR=$HOME/.wp-tests-lib` (what local CI uses). `/tmp/wordpress-tests-lib` is shared with other plugins and may point at a stopped database.
- Before the release: changelog entries for all of the above (none written yet), bump versions, refresh manifests.

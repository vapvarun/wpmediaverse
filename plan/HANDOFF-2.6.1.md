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

## Left in 2.6.1 (Ready for Development)

- 9937716241 Explore Trending sort + media-type filter. NOTE: larger than the card says. Trending exists in `MediaRepository::feed_page()` (REST), not in `query()`, which the Explore template uses; it needs the ranked path wired into `query()` or the template.
- 9941211270 Explore auto-load (IntersectionObserver on the existing loadMore(), keep the button).
- 10124085450 Upload-modal tag autocomplete (reuse media-social/view.js handler).
- 10252887530 One primary action on member empty states.
- 9822979354 Lightbox alt text from the REST payload.
- 9765027990 New-follower and comment emails on the existing switch.

## Waiting on the owner

- GET /mvs/v1/tags and /wp/v2/mvs_tag list tag names used only on private items (by design since a 2026-05-01 triage). Recommendation on card 10335795450: visitors see only tags on public items; signed-in uploaders keep the full list.

## Gotchas

- Run PHPUnit with `WP_TESTS_DIR=$HOME/.wp-tests-lib` (what local CI uses). `/tmp/wordpress-tests-lib` is shared with other plugins and may point at a stopped database.
- Before the release: changelog entries for all of the above (none written yet), bump versions, refresh manifests.

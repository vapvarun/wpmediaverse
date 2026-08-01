# Launch Kit

Templates for announcing a release. Fill the placeholders, delete what does not apply, and keep the claim rules from [Positioning & Messaging](positioning.md).

Two rules that matter more than the templates:

1. **Announce what shipped, not what is planned.** If it is not in the tag, it is not in the announcement.
2. **Lead with the fix or feature that a member notices**, not the one that was hardest to build. "Phone photos stop landing sideways" beats "EXIF Orientation normalisation in the upload pipeline."

## Release checklist

Before anything goes out:

- [ ] Both plugins tagged at the same version, and both zips built
- [ ] `readme.txt` changelog written in the action-prefix format (`New`, `Improve`, `Fix`, `Security`, `Dev`, `Compat`)
- [ ] What's New docs page added under `getting-started/` and registered in `docs_config.json`
- [ ] Any new hook, REST route, setting, shortcode, or block documented - see the coverage note in [Documentation coverage](#documentation-coverage)
- [ ] Free release body links the Pro tag, Pro release body links the Free tag
- [ ] Screenshots re-captured if a screen in them changed
- [ ] Claims in this kit re-checked against the shipped code

## GitHub release body

Title format: `WPMediaVerse X.Y.Z - one-line summary`. No em-dash, no emoji.

```
One-line summary of the release. Skip if the bullets speak for themselves.

* New      - Description.
* Improve  - Description.
* Fix      - Description.
* Security - Description.
* Dev      - Description.
* Compat   - Paired with WPMediaVerse Pro X.Y.Z. Install both updates together.

Full docs: <link to the What's New page in the repo>
```

Pad each label to 8 characters before ` - ` so the columns line up. Order within a version: New, Improve, Fix, Security, Dev, Compat.

## Email to existing customers

Subject options:

- `WPMediaVerse X.Y.Z: <the one thing they will notice>`
- `<Feature> is here`

```
Hi <name>,

WPMediaVerse X.Y.Z is out.

<One paragraph on the headline change, written from the member's point of view.
What was annoying before, and what happens now.>

Also in this release:

- <Second most noticeable change>
- <Third>
- <Fourth>

Update from Plugins > Updates, or download from your account.
<If a paired release: Update WPMediaVerse and WPMediaVerse Pro together.>

Full details: <link to What's New>

- The Wbcom Designs team
```

Keep it under 200 words. Customers skim.

## Social posts

**X / Twitter** - one change, one sentence, one image.

```
WPMediaVerse X.Y.Z is out.

<The single most noticeable change, in one sentence.>

<link>
```

**LinkedIn** - room for the why.

```
WPMediaVerse X.Y.Z is out.

<Two or three sentences on the problem this release solves for people running
a media community on WordPress.>

What's new:
- <change>
- <change>
- <change>

<link>
```

Attach `social/og-1200x630.png` for LinkedIn, `social/twitter-1600x900.png` for X.

## Feature announcement

For a release with one substantial new capability. Use as a blog post or a docs-site post.

```
# <Feature name>

## The problem

<What members or site owners could not do, in plain language. No feature names yet.>

## What we built

<What it does now. Screenshot here.>

## How to turn it on

<Exact steps: which settings screen, which toggle, which shortcode or block.
If it is on by default, say so.>

## For developers

<New hooks, REST routes, or settings, with links to the reference pages.>

## Availability

<Free or Pro. Which version. Whether the paired plugin needs updating too.>
```

## Support macro

For replies once a release is out:

```
This is fixed in WPMediaVerse X.Y.Z, released <date>.

Update from Plugins > Updates. <If paired: update WPMediaVerse Pro at the same
time - they release in lockstep and Pro X.Y.Z expects Free X.Y.Z.>

If you still see it after updating, reply here with your WordPress version, PHP
version, and whether BuddyPress is active, and we will take another look.
```

## Documentation coverage

A release is not ready to announce while a shipped surface is undocumented. The check is mechanical - compare the code against the docs:

| Surface | Where it must appear |
|---|---|
| REST route | [REST API](../website/developer-guide/rest-api.md) or [Pro REST API](../website/developer-guide/pro-rest-api.md) |
| Hook or filter | [Hooks & Filters](../website/developer-guide/hooks-filters.md) |
| Setting | [Settings Reference](../website/settings/settings-reference.md) |
| Shortcode | [Shortcodes](../website/features/shortcodes.md) |
| Block | [Blocks](../website/features/blocks.md) |
| WP-CLI command | [WP-CLI](../website/developer-guide/wp-cli.md) |

Two failure modes to check for, because both look fine from the docs side:

- **A documented thing that no longer exists.** A filter that was renamed or removed still reads convincingly in the docs, and a developer who registers it gets silence. Grep the codebase for every hook name the docs mention.
- **A route documented at the wrong path.** It looks complete and returns 404.

## Related

- [Positioning & Messaging](positioning.md)
- [Store listing copy](store-listing.md)
- [Marketing visuals](README.md)

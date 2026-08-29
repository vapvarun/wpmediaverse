# Positioning & Messaging

The reference for how WPMediaVerse is described - on the store page, in release announcements, in support replies, and in the docs. If you are writing anything customer-facing, start here so the product sounds like one product.

Everything in this file is checkable against the code. Do not add a claim you have not verified; a marketing page that promises a feature the plugin does not have becomes a support ticket and a refund.

## One sentence

> WPMediaVerse turns a WordPress site into a real media community - uploads, albums, social, and direct messages - without sending your members' media to somebody else's platform.

## The problem we solve

Site owners who want a media community on WordPress have had three bad options:

1. **Send members to a SaaS platform.** You lose the members, the data, and the ability to moderate on your own terms.
2. **Bolt together a gallery plugin, a social plugin, and a chat plugin.** Three plugins, three data models, three upgrade paths, and nothing shares a privacy model - so a photo can be private in one surface and public in another.
3. **Use a legacy media plugin.** Works, but was designed before block themes, the REST API, and mobile clients, so anything beyond the built-in screens means custom work.

WPMediaVerse is one plugin with one data model, one privacy model, and a complete REST API.

## Who it is for

**Primary: the community site owner.** Runs a BuddyPress, BuddyBoss, or standalone WordPress community of a few hundred to a few thousand members. Wants members to post photos and video, follow each other, comment, and message - and wants moderation tools when that goes wrong. Not a developer, but will paste a shortcode and configure a settings screen.

**Secondary: the WordPress developer or agency** building that site for a client. Cares about hooks, REST, template overrides, and whether the plugin will still be maintainable in two years.

**Tertiary: the site owner who wants to earn from it.** Sells storage tiers or premium membership. This is the Pro upgrade path - quota packages with MemberPress, PMPro, and WooCommerce.

## What makes it different

Lead with these. Each is a real, verifiable property of the product.

**One privacy model, enforced everywhere.** Six privacy levels - public, members, friends, group, private, custom - enforced in the query itself, so a private item never leaks through the grid, the feed, the REST API, or an activity post. Albums pass their privacy down to the media inside them. This is the claim competitors cannot easily match, because retrofitting a privacy model across separately-designed surfaces is close to impossible.

**Complete REST API.** Over 90 routes in the free plugin, covering everything the built-in screens do - not a read-only subset. That is what makes a native mobile client possible, and it is why a developer can build a completely custom front end without forking the plugin.

**Free is not a trial.** Uploads, albums, collections, the full social layer, direct messaging with voice messages, AI moderation, BuddyPress integration, GDPR export and erasure, and the REST API are all in the free plugin. Pro adds scale, video intelligence, monetization, and gamification - it does not unlock basics that were held back.

**Built for the WordPress that exists now.** Blocks with the Interactivity API, block themes, `theme.json` tokens, and dark mode. Not a 2015 plugin with a new skin.

**Media stays yours.** Files live in your uploads directory by default. Move them to S3, BunnyCDN, Cloudflare R2, or DigitalOcean Spaces with Pro if you want a CDN - but that is your decision, not a condition of using the plugin.

## What to say about Pro

Pro is for sites that have outgrown a single server or want to earn from media. Frame it as scale and revenue, never as "unlocking" something.

| Theme | The pitch |
|---|---|
| Scale | Offload to S3, BunnyCDN, R2, or Spaces for global CDN delivery |
| Video | Chapters, resume playback, auto-captions, engagement analytics |
| Revenue | Quota packages sold through MemberPress, PMPro, or WooCommerce |
| Engagement | Challenges, battles, tournaments, boosts, streaks |
| Identity | Instagram, Pinterest, Flickr, and Dribbble layout modes |
| AI | Google Vision, AWS Rekognition, and Claude for tagging and moderation |
| Migration | One WP-CLI command from rtMedia, MediaPress, or BuddyBoss |

## Feature to benefit

Write the benefit, not the feature. The feature belongs in the supporting sentence.

| Feature | Benefit to write |
|---|---|
| Six privacy levels | Members control who sees each upload, and the setting is actually enforced |
| Album privacy inheritance | A private album stays private - the contents cannot be public by accident |
| Duplicate detection | The same photo does not clutter the gallery five times |
| AI moderation | Problem content is caught before your members see it |
| Direct messages | Members talk to each other on your site instead of leaving for a chat app |
| EXIF stripping | Members do not publish their home address in a photo's GPS tag |
| Quota packages (Pro) | Storage becomes a product you sell, not a cost you absorb |
| Layout modes (Pro) | The community looks like the platform your members already use |
| Resume playback (Pro) | A long video picks up where the member left off |
| Gamification (Pro) | Members come back tomorrow |

## Words to avoid

- **"Unlimited"** anything - storage is a real cost, and the claim invites a refund request.
- **"Transcoding"**, **"HLS"**, **"adaptive streaming"**, **"multi-quality"** - removed in 2.4.0 and not coming back. MediaVerse embeds and plays media; it does not process it. The FFmpeg path was host-dependent (absent on most shared hosts) and its shell calls tripped security scanners. Say what is true instead: chapters, resume playback, auto-captions, engagement analytics. Selling a codec pipeline we do not ship is the same refund request as "unlimited", arriving a month later.
- **"Replaces Instagram."** It does not, and the comparison invites feature-by-feature scrutiny we lose.
- **"Enterprise-grade", "revolutionary", "seamless", "game-changing."** Say the specific thing instead.
- **Competitor names in a negative claim.** Describe the shape of the problem, not the rival's failings. The `compare-tech` card compares approaches, not brands.
- **Invented numbers.** No "used by 10,000 sites", no "50% faster", unless someone has measured it and can show the measurement.
- **Feature counts that drift.** If you cite a count, cite one from the [functionality catalog](../../audit/) and re-check it at release.

## Verified numbers

Re-verify before each release; these move.

| Claim | Value | Where to re-check |
|---|---|---|
| Free REST routes | 90+ route patterns | `GET /wp-json/mvs/v1` on a site with Free active |
| Shortcodes (Free + Pro) | 25 | `add_shortcode` call sites |
| Blocks | 26 registered, 22 in the inserter | `block.json` files |
| Hooks and filters | 292 | [Hooks & Filters](../website/developer-guide/hooks-filters.md) |
| Settings | 68 | [Settings Reference](../website/settings/settings-reference.md) |
| Minimum WordPress | 6.5 | plugin header |
| Minimum PHP | 7.4 | plugin header |

## Related

- [Store listing copy](store-listing.md)
- [Launch kit](launch-kit.md)
- [Free vs Pro](../website/getting-started/free-vs-pro.md) - the authoritative feature split
- [Marketing visuals](README.md)

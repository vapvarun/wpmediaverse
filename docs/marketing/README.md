# MediaVerse Marketing Visuals

Editorial-grade marketing assets for **WPMediaVerse · Pro** generated from the live `mediaverse.local` sandbox via the `plugin-marketing-visuals` skill.

## Direction

**Editorial Tech** - deep navy ink (`#0A0E1A`) + warm paper (`#F5F0E6`) + electric blue (`#2B4DF3`) + coral (`#FF6B3D`).
Typography: **Fraunces** (display, variable serif) + **Manrope** (body) + **JetBrains Mono** (labels).

## Assets

### `hero/`
| File | Dimensions | Use |
|---|---|---|
| `hero-laptop-1920x1080.png` | 1920×1080 | Website hero / blog lead image |
| `hero-phone-1080x1920.png` | 1080×1920 | Instagram / TikTok / vertical story |

### `feature-cards/`
| File | Dimensions | Feature |
|---|---|---|
| `card-01-my-media.png` | 1200×900 | My Media dashboard - "Every upload, one glance." |
| `card-02-compete.png` | 1200×900 | Compete hub - "Challenges, brackets, winners." |
| `card-03-albums.png` | 1200×900 | Albums & collections - "Galleries built for every story." |
| `card-04-creators.png` | 1200×900 | Creator community - "Follow every creator." |
| `card-05-media.png` | 1200×900 | Media detail + comments - "Reactions, comments, credit." |
| `card-06-gamification.png` | 1200×900 | Gamification hub - "Earn it as you go." |
| `compare-tech-1600x900.png` | 1600×900 | Tech comparison: MediaVerse vs SaaS vs legacy WP plugins |

### `social/`
| File | Dimensions | Use |
|---|---|---|
| `og-1200x630.png` | 1200×630 | OG image + LinkedIn post preview |
| `twitter-1600x900.png` | 1600×900 | Twitter / X banner + LinkedIn post card |

### `raw/`
Clean Playwright captures (source of truth), element-cropped to focus on the feature not the chrome. Used as input for the composite templates.

### `source/`
HTML/CSS composite templates. Served via WP nginx at:
`http://mediaverse.local/wp-content/plugins/wpmediaverse/docs/marketing/source/<name>.html`

Edit + re-screenshot to update any asset.

## Regeneration

1. Launch the `plugin-marketing-visuals` skill in Claude Code
2. Point it at this `docs/marketing/` folder
3. The skill will re-run `.capture-manifest.json` via Playwright MCP and overwrite the raws + composites

**Fonts:** templates load Fraunces / Manrope / JetBrains Mono from Google Fonts. Wait ~2s before screenshotting each template so the web fonts have time to paint.

## Copy positioning

**Brand**: WPMediaVerse · Pro - A Wbcom Designs product
**Headline**: "Your community, their lens."
**Lede**: "A full social media platform for WordPress creators - albums, collections, photo challenges, tournaments, gamification, direct messages and more, all running on the stack you already own."

**Stats (hero caption)**:
- Albums: 8
- Media Items: 145
- Competitions: 12
- Creators: 15

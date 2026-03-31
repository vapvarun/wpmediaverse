# WPMediaVerse Persona Profiles

Five detailed profiles of the people who buy and use WPMediaVerse. Use these when writing copy, choosing feature angles, or deciding which objections to lead with.

---

## Persona 1: The Community Admin

**Name:** Sandra
**Role:** Runs a photography forum and community site
**Community size:** 500–5,000 members
**Technical level:** Low to moderate — comfortable with WordPress, not a developer

### Background

Sandra started her photography community on a Facebook group seven years ago. She moved it to WordPress two years back using BuddyPress, because she wanted to own her data and stop fighting with Facebook's algorithm. The site runs well enough, but media has always been the weak point. Members post photos in activity feeds, which looks cluttered. Albums are basic. There is no way to do photo competitions or showcase member work properly.

She spends most of her admin time on moderation — approving uploads, removing inappropriate content, responding to reported posts. She has no developer on retainer. When something breaks, she either googles it or posts in the WordPress support forums.

### What She Needs

- A media section that looks good without custom CSS
- Moderation tools that do not require her to review every single upload
- Albums so members can organise their work
- Something that works alongside her existing BuddyPress setup, not instead of it
- Pricing she can justify — this is a passion project, not a business

### Her Biggest Fear

Installing a plugin that breaks her existing site, or one she cannot figure out on her own.

### What Wins Her Over

- A working demo she can click around before buying
- "Works with BuddyPress" front and centre
- AI moderation that catches the obvious stuff automatically
- Clear documentation with screenshots, not code

### Quote That Sounds Like Her

"I just want members to be able to share photos and have it look nice. I don't need anything complicated."

### Messaging Angle

Lead with ease of use and BuddyPress compatibility. Show the Instagram-style feed. Mention AI moderation upfront because it directly solves her biggest time drain. Free plan with upgrade path to Pro when the community grows.

---

## Persona 2: The BuddyPress Developer

**Name:** Marcus
**Role:** Freelance WordPress developer, specialises in BuddyPress community sites
**Clients:** Agencies, associations, online learning platforms
**Technical level:** High — PHP, custom hooks, REST API, JavaScript

### Background

Marcus builds 6–10 BuddyPress sites per year for clients ranging from professional associations to niche hobby communities. He has used rtMedia on dozens of sites and knows its limitations by heart — the activity feed integration is fragile, the REST API is thin, and customising the UI requires fighting the plugin rather than extending it.

He evaluates plugins differently from end users. Before recommending anything to a client, he reads the source, tests the hooks, and checks whether the database schema will hold up at scale. He is not interested in marketing claims — he wants to know what actions and filters are available, whether custom tables are indexed properly, and how much technical debt he will inherit.

He charges clients for the plugins he recommends, so if a plugin causes support headaches down the line, it costs him real money.

### What He Needs

- Documented action and filter hooks for every major operation
- REST API endpoints he can build on
- Database schema that uses custom tables (not abusing wp_posts)
- BuddyPress integration that is additive, not a replacement
- A plugin he can confidently recommend to clients without babysitting it

### His Biggest Fear

Recommending a plugin to five clients, then discovering a bug or a breaking change in an update that he has to fix across all five sites at once.

### What Wins Him Over

- Custom table architecture clearly explained (not stuffed into wp_posts)
- Public GitHub repo or at least a changelog with meaningful entries
- REST API documentation with example requests
- A developer filter reference in the docs

### Quote That Sounds Like Him

"I need to know what I can hook into before I commit to recommending this to clients."

### Messaging Angle

Lead with architecture. Custom tables, filterable helpers like `TemplateHelpers::get_thumb_url()`, documented REST endpoints. Show the gamification manifest pattern — a single integration file that works because the architecture is clean. Avoid lifestyle copy entirely.

---

## Persona 3: The School or University Admin

**Name:** Dr. Priya Nair
**Role:** Digital learning coordinator at a mid-size university
**Department:** Arts, Communications, or Journalism
**Technical level:** Moderate — manages WordPress multisite, works with IT department

### Background

Priya runs the department's student portfolio platform. Students submit photo essays, video projects, and design work through it. Faculty review submissions. The platform needs to handle 400–600 active students per semester, with strict rules about storage — IT allocates a fixed quota per student, and going over it has caused headaches in the past.

She also needs content moderation to be reliable. The platform is public-facing and tied to the university's reputation. One inappropriate upload that stays up for 24 hours creates a real problem for her.

Privacy matters too. Some students want their portfolios public; others want work visible only to faculty and classmates. She needs granular controls, not a binary public/private switch.

GDPR compliance is non-negotiable — the university has a legal team that will ask about it specifically before approving any new plugin.

### What She Needs

- Per-user storage quotas that integrate with existing membership or role systems
- AI moderation with configurable sensitivity settings
- Per-item privacy controls (public, members only, faculty only, private)
- Video support with reasonable transcoding — students submit MP4s, not raw files
- GDPR-compliant data handling with a clear data export/deletion path

### Her Biggest Fear

A student submitting inappropriate content that stays up because moderation failed, or a storage overrun that brings IT down on her.

### What Wins Her Over

- A quota system she can configure without developer help
- GDPR documentation she can hand to the legal team
- A trial period or demo environment she can test before proposing to IT
- MemberPress or WooCommerce integration for quota tiers (IT already uses one of these)

### Quote That Sounds Like Her

"Before I take this to IT, I need to know it handles quotas properly and that we can demonstrate GDPR compliance."

### Messaging Angle

Lead with quotas and moderation. Mention MemberPress and WooCommerce integration by name. Put GDPR on the table proactively — do not make her dig for it. Video transcoding with HLS and captions is a strong secondary point for the journalism and film programs.

---

## Persona 4: The Photography Club Owner

**Name:** James
**Role:** President of a regional photography club, semi-professional photographer
**Community size:** 80–300 members, with an active online component
**Technical level:** Low to moderate — uses WordPress with page builder, not a coder

### Background

James has been running his photography club for eight years. The club holds monthly competitions — a theme is announced, members submit photos, a panel votes, and winners are recognised. He moved the club online during the pandemic and never fully went back. The digital competitions have higher participation than the in-person ones ever did.

The problem is that he is running competitions through a mix of a contact form, a Google Sheet for tracking entries, and a private Facebook album for voting. It is fragile and embarrassing for a club this size. He wants a proper competition platform built into the site.

He also cares about how member work is displayed. Right now everything goes into a BuddyPress activity feed and gets buried. He wants something that looks more like a portfolio showcase — maybe a grid or masonry layout — so he can share links to member work publicly.

### What He Needs

- Photo competitions with entry submission, voting, and a leaderboard
- A way to showcase member work in a gallery or portfolio layout
- Gamification elements — points, badges, recognition — that keep members engaged between competitions
- Multiple layout modes so the media section looks like a gallery, not a social feed
- Something he can manage without writing code

### His Biggest Fear

Members not engaging with it, or a competition feature that is too complicated for members to figure out without hand-holding.

### What Wins Him Over

- A working competition demo he can show to club members
- Clear explanation of how battles and tournaments work (with screenshots)
- A Pinterest or Flickr-style layout mode that looks like a real portfolio
- Gamification that rewards consistent participation, not just winning

### Quote That Sounds Like Him

"We've been doing competitions in a spreadsheet. There has to be a better way, and I want it to look good when members share their work publicly."

### Messaging Angle

Lead with competitions and gamification — Photo Battles, Challenges, Tournaments. Pair it with the gallery/masonry layout modes so he can see the portfolio angle. Points and badges are motivators for this audience. Keep technical detail minimal; focus on outcomes and show the UI.

---

## Persona 5: The Agency Owner

**Name:** Rachel
**Role:** Owner of a 4-person WordPress agency
**Client types:** Associations, niche communities, membership sites, education
**Technical level:** Moderate — manages projects, does some WordPress work, delegates development

### Background

Rachel's agency builds 15–25 WordPress sites per year. Media management comes up as a requirement on roughly half of them. She has tried rtMedia, MediaPress, and BuddyBoss Platform on various projects. Each has let her down in a different way — rtMedia's UI looks dated and clients notice, MediaPress has limited extensibility, BuddyBoss is expensive and locks clients into a theme.

She evaluates plugins through an agency lens: what is the long-term support cost, can she customise it without hacking core files, and can she hand it off to a client who will manage it without calling her every month. White-label readiness matters — she does not want WPMediaVerse branding showing up in client admin screens.

She is also thinking about margin. If she can resell a Pro licence to each client site, that is recurring revenue. She wants to understand the licensing terms and whether a developer or agency licence exists.

### What She Needs

- A plugin she can recommend across multiple client projects without technology lock-in
- Extensible architecture with documented hooks so her developer can customise
- Admin UI that looks polished — clients judge the product by the admin screen
- Agency or developer licensing — ideally unlimited sites or a reasonable per-site model
- Works without requiring a specific theme (unlike BuddyBoss)
- S3 or BunnyCDN integration so large client media libraries do not destroy hosting bills

### Her Biggest Fear

Recommending a plugin that gets abandoned, or one that requires her team to maintain a fork because the developer API is too thin.

### What Wins Her Over

- A clear product roadmap or public changelog that shows active development
- Agency licensing at a price she can build into project quotes
- Cloud storage integration that actually works out of the box
- A demo site that looks like something she could show a client

### Quote That Sounds Like Her

"I need something my clients can manage themselves and that I can customise when they need something specific. I'm not looking for another plugin I have to maintain."

### Messaging Angle

Lead with flexibility, architecture quality, and licensing. Mention cloud storage (S3/BunnyCDN) because that directly addresses a real cost concern for client sites. Emphasise that WPMediaVerse works with any theme. Show the admin UI quality. Put agency licensing front and centre on the pricing page.

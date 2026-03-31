# WPMediaVerse Gamification

**Turn your media community into an active, competitive, self-motivating platform — without running contests by hand.**

---

## The Problem With Passive Communities

Most media communities follow the same pattern: early adopters upload a lot, activity peaks, then gradually the only person still uploading is the person who runs the site.

The usual fix is manual effort. Community managers run occasional contests, announce winners on social media, and hope people show up for the next one. It works for a while, but it does not scale.

What actually drives sustained activity is a sense of progress, competition, and recognition that is built into the experience — not bolted on when things slow down.

---

## What the Gamification System Does

WPMediaVerse Pro includes a full gamification engine that rewards community activity automatically, surfaces competitive events that run themselves, and gives your most active members a way to earn visible status.

The system is built on wb-gamification, a standalone points engine. WPMediaVerse Pro registers 14 media-specific actions. Every action earns points. Points unlock levels, badges, and boosts.

---

## The 14 Point-Earning Actions

Every action is tracked automatically. No setup required beyond installing Pro.

| Action | Points Awarded | Who Earns |
|--------|----------------|-----------|
| Upload a photo | 10 | Uploader |
| Create an album | 15 | Creator |
| Receive a like | 2 | Media owner |
| Receive a comment | 5 | Media owner |
| Receive a follow | 3 | Followed user |
| Receive a favorite | 2 | Media owner |
| Leave a comment (20+ characters) | 3 | Commenter |
| Follow another member | 1 | Follower |
| Bookmark a photo | 1 | Bookmarker |
| Win a 1v1 battle | 100 | Winner |
| Enter a challenge | 10 | Entrant |
| Win a challenge | 200 | First place |
| Win a tournament round | 150 | Round winner |
| Win a tournament | 500 | Champion |

Points compound over time. A member who uploads consistently, receives engagement, and participates in events builds a points balance that meaningfully separates them from casual participants.

---

## Photo Challenges

Admins create community challenges with a theme, a submission window, and a voting period. Members submit their best photo for the theme. The community votes. Rankings are published.

**Why it works:** Challenges create a shared calendar event. Everyone in the community knows the theme, knows the deadline, and knows that results will be announced. Members who would not upload unprompted will upload for a challenge because there is a specific reason to.

**How to run one:**
1. Create a challenge in the admin panel with a title, theme description, and dates
2. Members submit photos via the `/media/challenges/` page
3. Voting opens automatically when the submission window closes
4. Results are ranked by vote count and displayed publicly

The challenge page shows active, voting, and completed challenges with tabs. Members can view all entries for any challenge and vote directly from the entry list.

**Points:** Entering earns 10 points. First place earns 200 points. Every participant is acknowledged, not just the winner.

---

## 1v1 Photo Battles

A member challenges another member to a head-to-head photo comparison on a given theme. Both submit a photo. The community votes within a set window. The winner takes the points.

**Why it works:** Battles are personal. A challenge is a community event; a battle is a direct call-out between two specific people. Their followers care about the outcome. Voting participation from non-participants is significantly higher for battles than for open challenges, because people have a rooting interest.

**The experience:**
- The battle page shows challenger and opponent photos side-by-side
- Status badge shows whether the battle is in the voting window, active, or completed
- Vote buttons are visible during the voting window and hidden otherwise
- Vote counts are shown for completed battles

**Points:** The winner earns 100 points.

---

## Tournament Brackets

Multiple members register for a tournament. The bracket system pairs entrants in rounds, runs community voting on each match, advances winners, and crowns a champion.

**Why it works:** Tournaments run for days or weeks. Each round is a new event that brings people back to vote. Members track their favorite participants through the bracket. The community conversation around a tournament sustains engagement in a way that a single challenge cannot.

**The experience:**
- The tournament page shows open, in-progress, and completed tournaments
- Each card shows participant count, spots remaining, and a register button for open tournaments
- Active and completed tournaments show a bracket visualization with round-by-round match cards
- Players are identified by name with running vote counts per match

**Points:** Winning a round earns 150 points. Winning the tournament earns 500 points — the highest single award in the system.

---

## Boosts

Members spend their accumulated points to "boost" a media item. A boosted item gets elevated placement in the explore feed for a set period.

**Why it works:** Boosts create a soft economy inside the community. Points earned through activity can be converted into reach. Members who are most active — and therefore most deserving of visibility — have the most to spend. It is a self-correcting meritocracy that does not require admin intervention.

**For site owners:** Boosts are a natural differentiator for membership tiers. Members on higher-tier plans can receive a monthly points bonus that gives them more boost capacity without buying points directly.

---

## Streaks

Members who upload on consecutive days build a streak. Streaks are visible on profiles and earn bonus points for each consecutive day. Breaking the streak resets to zero.

**Why it works:** Streaks create a loss-aversion dynamic — the pain of losing a 14-day streak is a real motivator to upload even when a member is not particularly inspired. This is one of the most reliable drivers of daily active user counts across social platforms.

---

## The Leaderboard

A site-wide leaderboard ranks members by total points. It is visible on the community pages and updates in real time as actions are taken.

**Why it matters:** The leaderboard gives top contributors public recognition. New members can see who the most active community members are and follow them. Active members can see how they rank against each other.

---

## Who This Feature Is For

The gamification system is designed for communities where sustained member activity is a goal — not just initial sign-up and first upload.

**Photography communities** running seasonal challenges and annual tournaments. Members stay year-round because there is always a next event.

**Creative communities** where members are competitive about their work and want a structured way to test themselves against each other.

**Membership sites** where points bonuses for higher tiers create a tangible reason to upgrade beyond just feature access.

**Community managers** who want events to run automatically rather than requiring manual coordination for every contest.

---

## Setup

The gamification system activates automatically with Pro. No manual action registration is required. The 14 actions are pre-configured in the wb-gamification manifest.

To configure the system:

1. Set point values per action in the gamification admin panel (the defaults above are the starting values)
2. Set boost costs — how many points to boost a media item, and for how long
3. Create your first challenge via Media > Gamification > New Challenge
4. Optionally configure streak bonus multipliers

The challenge, battle, and tournament pages (`/media/challenges/`, `/media/battles/`, `/media/tournaments/`) are created automatically at Pro activation.

---

## Get Gamification

Gamification is included in WPMediaVerse Pro. Every Pro license tier includes the full gamification engine.

**[Get WPMediaVerse Pro]**

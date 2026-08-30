# Bunny Stream video encoding — design plan

**Status:** PLAN ONLY. Nothing implemented. Written 2026-08-30 for review before any code.
**Owner decision needed on §7 and §8 before this can be built.**

## 1. What this is for

A member uploads a video. It gets encoded into multiple qualities. The player then uses the
encoded URLs instead of the original file. That is the whole feature in one sentence, and the
plan below is mostly about the four places that sentence hides a decision.

MediaVerse removed FFmpeg in 2.4.0 for a reason that has not changed: shipping `exec()` /
`proc_open()` gets the plugin flagged by security scanners as a possible backdoor, regardless of
whether any customer's server can run it (commit `c96415ba`, Basecamp 10232926505). A cloud
encoder is an HTTP call. The scanner objection does not apply to it.

## 2. Why Bunny, specifically

- **Standard encoding is free.** Not per-minute. Mux and Cloudflare Stream both bill per encoded
  minute; Bunny includes standard transcoding in the storage price. Premium encoding is opt-in at
  $0.025/min (low res) to $0.150/min (4K). Stream storage is $0.01/GB/month.
- We already ship a BunnyCDN storage driver, so it is one vendor and one invoice for the site owner.
- Its **iframe player removes the hardest piece of work** — see §6.

**Correction to an earlier claim of mine:** this does NOT reuse the customer's existing BunnyCDN
credentials. Stream needs a Video Library ID and a Stream API key, which are different from a
Storage Zone name and password. Same account and same bill; different keys and a different API.

Source: https://bunny.net/docs/stream/pricing (checked 2026-08-30 — re-check before committing to
pricing in any customer-facing copy).

## 3. THE key finding: Stream is not a storage driver

`StorageDriverInterface` is a path-addressed file store:

```php
store( string $source_path, string $dest_path ): bool;
url( string $path ): string;
exists( string $path ): bool;
download( string $path, string $local_dest ): bool;
```

Bunny Stream does not fit any of those:

| Interface assumes | Stream actually does |
|---|---|
| you choose the destination path | it returns a **GUID** it chose |
| `url($path)` is derivable and immediate | playback URL is library + GUID, and **not valid until encoding finishes** |
| `exists()` answers "is the file there" | the file is there but **unplayable** for seconds to minutes |
| `download()` returns the bytes | the original may not be retrievable at all |

**Do not implement Stream as a storage driver.** Forcing it through that interface makes `exists()`
lie and `url()` return something that 404s for the first minute of every upload's life. It is a
separate integration that happens to be about video, and it is registered on its own seam.

This is the single most expensive thing to get wrong, because it is discovered late — the driver
shape looks right until the first real upload.

## 4. The flow

```
member uploads .mp4
        │
        ▼
UploadService::handle()  ── stores the original exactly as today (unchanged)
        │
        ▼
do_action( 'mvs_media_uploaded', $media_id, $data, $user_id, $media_type )
        │
        └─► Pro: StreamIngest listener
                 ├─ is this a video? is Stream configured? is the licence live?  → else return
                 ├─ create video in the Library (POST), get GUID
                 ├─ upload the bytes (PUT)
                 ├─ media_meta: mvs_stream_guid = <guid>, mvs_stream_state = 'encoding'
                 └─ return (member's request ends here — no waiting)

        ... Bunny encodes, seconds to minutes ...

Bunny webhook  ──►  /mvs-pro/v1/stream/webhook
                    ├─ verify signature
                    ├─ media_meta: mvs_stream_state = 'ready'
                    └─ CacheService invalidate for that media

playback: MediaUrl / the player asks
          mvs_stream_state === 'ready'  ? Bunny iframe embed
                                        : the original file (today's behaviour)
```

**The original is always kept and always stored as it is today.** Stream is additive. That single
choice makes every failure mode in §9 recoverable, because there is always something to fall back
to. It costs storage; that is the price of the feature not being able to lose a member's video.

## 5. Where the URL swap happens

Two existing filters already do this, so no new seam is needed for playback:

- `mvs_public_local_file_url` / `mvs_public_cloud_file_url` (SignedUrlService:901, 908)

Pro hooks them, returns the Stream playback URL when `mvs_stream_state === 'ready'`, and returns
the value unchanged otherwise. Nothing in Free changes.

## 6. The player — and how the hard part disappears

A raw HLS manifest in `<video src>` plays **only in Safari**. Chrome, Firefox and Edge do not
decode HLS natively, so shipping a `.m3u8` without hls.js would give most members a broken player.

Bunny provides an **iframe embed player**, which sidesteps MSE entirely: for a ready video we
render an `<iframe>` instead of a `<video>`. No hls.js, no player rewrite.

The cost is real and should be stated to the owner rather than discovered: inside an iframe we lose
our own player chrome, which means **chapters and resume playback (Pro features that exist today)
stop being ours to draw**. Bunny has its own chapter and resume support; they are not the same
implementation and not driven by our REST routes.

Options, in order of preference:

1. **Iframe for Stream-encoded video, our `<video>` for everything else.** Cheapest. Accept that
   chapters/resume come from Bunny for those items and audit whether our `VideoController` and
   `ResumeService` should be hidden for them rather than showing controls that do nothing.
2. **hls.js + our own `<video>`.** Keeps chapters, resume, retention heatmaps and captions exactly
   as they are. Adds a JS dependency and MSE edge cases. More work, no feature loss.

Option 1 is the fast path; option 2 is the one that does not quietly remove two Pro features. **This
is an owner decision, not a technical one** — see §12.

## 7. OPEN DECISION — privacy

`StorageService::get_driver_for_privacy()` sends only **public** media to cloud storage. Private and
restricted media never leave local disk, deliberately (1.4.0).

Bunny Stream does not fit that rule cleanly. The questions:

- Does a **private** video go to Stream at all? If no, private videos never get encoding, and the
  feature silently does nothing for them — which must be said in the settings screen, not learned.
- If yes, Stream needs token authentication and we are trusting a third party with private member
  media, which is a different promise from the one the privacy architecture currently makes.
- **What happens when a member flips a Stream-encoded video from public to private?** The asset is
  already in Bunny. Do we delete it from Stream and fall back to the local original (losing the
  encode, and the encode cost), or leave it and rely on token auth?

**Recommendation: phase one is public video only.** It matches the existing cloud rule exactly, needs
no new trust decision, and a privacy flip to private falls back to the local original — which we
still have, per §4. Document the limitation plainly in the settings screen.

## 8. OPEN DECISION — where a member's videos live

Videos in a Video Library, everything else in the Storage Zone. One account, two destinations.

A site owner will ask why their videos are not in the storage zone they are paying for. The answer
belongs in the settings screen, next to the toggle. This is a support-load decision more than a
technical one.

## 9. Failure modes, each needing a defined answer

| What happens | Plan |
|---|---|
| Encoding fails at Bunny | `mvs_stream_state = 'failed'`; player uses the original. Member sees a working video, owner sees the failure in the log. Never a broken player. |
| Webhook never arrives | State stays `encoding` forever and the video plays from the original. A reconcile sweep (Action Scheduler, already bundled) polls anything `encoding` older than N minutes. |
| Bunny is down at upload time | Upload SUCCEEDS and the original is stored. Stream ingest is a listener, not a gate — an encoder outage must never block a member's upload. |
| Member trashes the video | `mvs_media_trashed` (added 2.4.0) → delete the Stream asset. Restore re-uploads, or keeps the original. |
| Media permanently deleted | `mvs_media_deleted` → delete the Stream asset. Orphaned remote assets are billable. |
| Licence lapses | Existing encoded videos keep playing. No new encodes. Same shape as the Documents licence gate. |
| Credentials wrong | Fail the ingest, log it, surface it in the settings screen. Do not retry forever against a 401. |

## 10. Settings

Mirrors the existing 5-provider storage pattern, so it is a shape the codebase and its owners
already know:

- Enable Stream encoding (off by default — it costs money and changes where videos live)
- Video Library ID
- Stream API key (through `render_secret_input()`, masked like every other credential)
- Premium encoding toggle, off, with the per-minute cost stated
- Read-only status line: "N videos encoded, N encoding, N failed"

## 10b. OPTION B — a Wbcom-run encoding service (FFmpeg in Docker)

Raised by the owner. Instead of sending video to Bunny, Wbcom runs the encoder: the plugin POSTs
the file to a Wbcom service, FFmpeg runs in a container, renditions come back.

**The plugin side is nearly identical to Option A** — same `mvs_media_uploaded` listener, same
async pending state, same webhook, same URL swap through the existing filters. Only the API client
differs. That matters for the decision: the integration should be written against a small provider
interface so the choice is not load-bearing, and can even be made after the plugin work starts.

### What it solves that Bunny does not

- **The revenue is Wbcom's.** With Bunny, the customer pays Bunny. This is a service you can price,
  bundle with a Pro tier, or meter through the credits/quota system you already ship.
- FFmpeg runs on **your** container, so the scanner objection is gone and the customer's host is
  irrelevant — the same two problems Bunny solves, solved without a third party in the middle.
- Full control of the quality ladder, output format and roadmap. No vendor deprecation risk.

### What it costs, honestly

You become a video infrastructure provider. That is an ops commitment, not a feature:

| Concern | Why it bites |
|---|---|
| **Egress, not CPU** | Encoding CPU is cheap. Moving video is where the money goes. This is the single biggest cost driver and it is easy to under-model. |
| Burst load | Fifty sites uploading 2 GB files in the same hour. Needs a real queue and autoscaling, or it is a 30-minute wait and a support ticket. |
| Uptime | An outage now stops encoding for every customer at once. Option A's outage is Bunny's problem and their SLA. |
| Data + liability | Members' private media transits and rests on your servers. GDPR posture, data residency, retention, DMCA, abuse. Bunny already carries this. |
| Support | "My video is stuck" becomes your ticket, not the customer's host's. |

### The variant that removes most of that: encode-and-return

**Do not host the video. Encode it and hand it back.**

The service accepts a file, returns renditions, and the customer stores them in the storage they
already configured — local, S3, R2, Bunny Storage, DigitalOcean. Wbcom never serves playback.

That single choice removes egress at scale (you pay to receive and return once, not to serve every
view), removes the CDN, removes long-term storage of customer media, and shrinks the data-protection
surface to transient processing. It also fits the existing architecture exactly, because the storage
drivers and `MediaVariantWriter` already know how to place derived files.

It is worse than Bunny in one respect worth naming: no adaptive bitrate. You would hand back a small
set of MP4 renditions and let the player pick one, rather than a real HLS ladder. For most community
video that is fine and it is what the old FFmpeg path did anyway. It is not "adaptive streaming",
and the copy must not say so.

### Recommendation

Not either/or, and not now. **Write the plugin against a provider interface, ship one provider
first.** Bunny is the lower-risk first provider precisely because none of the ops above is yours;
the Wbcom service is the better business if the volume justifies running it, and encode-and-return
is the version worth costing first.

What would decide it: expected videos/month across the install base, and average file size. Neither
is known today, and guessing them is how infrastructure gets built for load that never arrives — or
falls over on load nobody modelled.

## 11. What is deliberately NOT in this plan

- Server-side FFmpeg. Already possible today for a capable site with zero core changes: hook
  `mvs_media_uploaded`, transcode in their own mu-plugin, filter the URL. Their code, their scanner
  surface. Worth a docs recipe; not worth core support.
- Live streaming / RTMP. Roadmap item, different feature (MASTER_PLAN.md:102).
- Migrating existing videos. A backfill CLI command is a follow-up once the ingest path is proven.
- DRM, per-video watermarking through Bunny.

## 12. What I need from the owner before building

0. **§10b — who runs the encoder?** Bunny (their ops, their SLA, free standard encoding, their
   revenue) or a Wbcom service (your ops, your liability, your revenue). Answer with expected
   videos/month and average file size, or it is a guess. Either way the plugin should be written
   against a provider interface so this is not load-bearing.
1. **§6 — iframe or hls.js?** Iframe is much cheaper but Bunny's player draws the chapters and
   resume, not ours. Which matters more: shipping speed, or keeping those two Pro features on our
   own implementation?
2. **§7 — public-only for phase one?** Recommended, and it needs saying out loud because it means
   private videos get no encoding at all.
3. **Free or Pro?** Assumed Pro throughout (it is a paid third-party service), gated like Documents.
4. Confirm Bunny Stream pricing independently before it appears in any customer-facing copy. I read
   it from their docs once, on one date.

## 13. Rough shape of the work, once those are answered

| Piece | Size |
|---|---|
| Settings + credentials | small — existing pattern |
| StreamIngest listener on `mvs_media_uploaded` | small |
| Bunny Stream API client (create, upload, delete, status) | medium |
| Webhook route + signature verification | medium |
| Reconcile sweep for missed webhooks | small — Action Scheduler is bundled |
| Playback swap via existing URL filters | small |
| Player branch (iframe) | small · (hls.js) medium |
| Trash / delete / licence-lapse handling | small — hooks exist |
| Journeys + smoke rows + REQUIRED-COVERS entry | small, and not optional |

The API client and the webhook are the real work. Everything else rides seams that already exist,
which is the argument for doing it this way rather than as a storage driver.

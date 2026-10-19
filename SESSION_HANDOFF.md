# Session Handoff — Velvet Vixxen 0.8.1 Continuation

## Resume here

Project: **Nikki Wilder / The Velvet Vixxen**  
Repository: `afterburn25/velvet_vixxen`  
Authoritative continuation branch: `handoff/0.8.1-incomplete-site-ux`  
Tracking issue: `#13`  
Recommended implementation branch: `feature/0.8.1-site-ux-hero-library`

Read these files before making implementation claims:

```text
INCOMPLETE_WORK.md
NEXT_SESSION_PROMPT.md
PROJECT_STATUS.md
SESSION_HANDOFF.md
docs/ROADMAP.md
docs/TEST_REPORT.md
```

`INCOMPLETE_WORK.md` is the authoritative scope for the user-facing work that remained unfinished or unverified after the documented 0.8.0 Marketing, Publishing & Analytics milestone.

## Critical truth boundary

The repository documents a packaged `0.8.0 — Marketing, Publishing & Analytics` runtime release with recorded SHA-256:

```text
ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993
```

However, the complete executable 0.8.0 engineering source and full PHP/MySQL/Playwright CI were not imported and verified in GitHub at handoff time. Do not rely on earlier chat statements that later UI features were “built.” Inspect the actual live branches, files, artifacts, and test evidence first.

The next session must identify the newest branch or artifact that genuinely contains runnable source. If the latest branch is documentation-only, locate/import the latest clean engineering source before adding more features. Do not reconstruct missing implementation by guessing from status prose.

## Locked product decisions

- Approved neon-pink, magenta, black, and white Velvet Vixxen design.
- No new mockups unless the user explicitly reopens design exploration.
- Final public scenes contain no placeholder models. Nikki’s transparent cutout photos remain separately movable layers.
- One simplified responsive browser installer page.
- No installation key.
- Exact 12-character minimum owner/member passwords.
- Safe `0755`/`0775`; never `0777`.
- Ownership-safe writable-directory repair.
- Organized application folders; no root-file sprawl.
- Customer installation ZIPs contain only files required to install and run the site.
- Repository docs, tests, SDK source, CI workflows, screenshots, and build tooling stay outside customer ZIPs.
- One Entitlement Resolver continues to control subscription, PPV, rental, bundle, gift, and complimentary access.
- PPV photos/videos are direct content purchases, not ordinary merchandise-cart items.
- Payment remains sandbox-only until a live adult-compatible provider is approved and certified.
- Canva remains a manual design/export/import bridge unless a real OAuth/API integration is implemented and tested.
- Never commit production credentials, customer/member/subscriber data, identity records, private messages or attachments, booking/request data, payment secrets, raw license keys, private signing keys, runtime state, logs, sessions, or private/paid media.

## Incomplete 0.8.1 work

The detailed requirements and acceptance tests are in `INCOMPLETE_WORK.md`. The main work groups are:

1. Recover/import and verify the real executable baseline.
2. Finish Visual Composer direct manipulation, extreme resizing, off-canvas clipping, movable live text/buttons, device-specific layouts, background pan, and broken-media repair.
3. Integrate at least 30 individual, people-free, luxury Hero scenes covering indoor and outdoor settings.
4. Build the admin-configurable Booking Form Builder with required/optional text, textarea, email, phone, dropdown, radio, and checkbox fields plus live phone formatting.
5. Make public navigation sticky, remove Contact, add About Me after Booking, build the About Me stats/bio editor, and center all auth forms in full-page layouts.
6. Reorganize admin navigation into persistent collapsible categories.
7. Build a real merchandise store while preserving direct Buy Now/rental flows for PPV galleries and videos.
8. Clarify Canva behavior and keep model cutouts as separate Visual Composer layers.

## Hero asset recovery note

Several standalone luxury backgrounds were generated in the prior workspace but were not committed to GitHub or verified as integrated. Their filenames are listed in `INCOMPLETE_WORK.md`. Additional Adobe-generated scene URLs were temporary and were not persisted. Recover the files if accessible; otherwise regenerate equivalent scenes and commit only appropriately optimized, non-sensitive runtime assets.

## Existing 0.8.0 documented scope

The `feature/0.8.0-marketing-publishing-analytics` branch records the following packaged functionality:

- Blog, personal updates, and announcements
- Scheduled/expiring content and audience visibility
- Newsletter consent, confirmation, unsubscribe, suppression, tags, and segments
- Campaign queues and timed promotional banners
- Campaign landing pages
- Scheduled Hero/Footer scene snapshots
- Link-in-bio and referral attribution
- Same-origin first-party analytics, summaries, CSV exports, and retention controls
- Operational marketing task runner
- Twelve migrations and 75 declared tables

Treat that scope as documented package evidence, not proof that the complete executable source is present in GitHub.

## Required validation before completion

- PHP 8.2 and 8.3 source suite
- JavaScript syntax validation
- Disposable MySQL 8 migration apply/verify/rollback
- Installer and shared-hosting ownership/write regression tests
- HTTP acceptance
- Desktop/mobile Playwright covering Visual Composer, background library, booking builder, sticky navigation, auth pages, About Me, admin state persistence, merchandise, and direct PPV purchase
- Zero broken images in Visual Composer and public Hero/Footer
- At least 30 selectable individual luxury backgrounds
- Repository safety and secret scans
- Runtime-only customer ZIP exclusions and clean uninstalled state

## Next session behavior

At the start of the next session:

1. Inspect live GitHub instead of relying on this chat.
2. Report which branch actually contains executable source.
3. State what is implemented versus documentation-only.
4. Create or switch to `feature/0.8.1-site-ux-hero-library`.
5. Begin concrete implementation and tests rather than returning another roadmap-only answer.
6. Update issue #13 and all handoff/status/test documents as work lands.

Do not begin 0.9.0 Compliance, Privacy & Operational Hardening until the 0.8.1 user-facing continuation is verified or the user explicitly defers it.

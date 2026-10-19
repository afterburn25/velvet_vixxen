# Agent Instructions — Velvet Vixxen

## Source of truth

Repository: `afterburn25/velvet_vixxen`

Current integration target: **0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise**

Verified runtime checkpoint: `ca527935e0737f55b0b79ea8d88539e0c44bf97c`, GitHub run [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), all six required jobs passed. Exact artifacts and package checksums are in `docs/TEST_REPORT.md` and `RELEASE_MANIFEST.txt`. Inspect the live branch and PR state before claiming merge or deployment.

Active branch: `feature/0.8.1-site-ux-hero-library`; `main` is the authoritative integration destination.

Read `PROJECT_STATUS.md`, `SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/TESTING.md`, `docs/TEST_REPORT.md`, `RELEASE_MANIFEST.txt`, and `SECURITY.md` before changing code.

The user requires the latest source and progress documents to be uploaded to GitHub. Commit and upload executable changes, tests, and progress/handoff documentation before a completed-session handoff. Never defer source import while claiming a release is complete. Customer runtime packages and engineering source have different contents, but must be built from the same committed implementation. A missing source tree must fail CI, not produce a passing skipped test job. Verify the actual commit, workflow, merge, and deployment state before making claims.

## Locked requirements

- Preserve the approved neon-pink/magenta/black design.
- Do not create more mockups unless explicitly requested.
- Keep the installer on one simplified responsive browser page.
- Require no installation key.
- Use a 12-character minimum password, not 14.
- Never use `chmod 0777`.
- Preserve ownership-safe writable-directory repair.
- Keep application files organized.
- Preserve Visual Composer, Canva workflow, Media Center, members, entitlements, PPV/subscriptions, add-ons, licensing, communications, publishing, newsletter, promotions, referrals, and analytics.
- Customer ZIPs contain only files required to install and run the live site.
- Repository docs, tests, SDK source, workflows, screenshots, and development tooling remain outside the customer ZIP.

## 0.8.0 invariants

- Marketing modules can be disabled independently; disabled public routes return 404.
- Unsubscribe remains available even when public newsletter signup is disabled.
- Newsletter campaigns honor confirmation, consent, unsubscribe, and suppression state.
- Private message bodies never enter email alerts or analytics payloads.
- Analytics reject foreign-origin collection and prohibited sensitive fields.
- Analytics never contain raw card data, protected-media contents, identity documents, or attachment contents.
- Referral attribution is preserved through registration and checkout without exposing private data.
- Scheduled scene publication is lock-aware, idempotent, and uses a `processing` claim state.
- File-store scene publication must re-read the scene store after publishing and must not overwrite fresh scene data with stale state.
- State-changing owner and public requests require CSRF or signed-token validation as appropriate.
- CSV exports must escape spreadsheet formula prefixes.
- Retention cleanup must not remove active consent/suppression records needed for compliance.

## Active continuation

Read `INCOMPLETE_WORK.md` in full. Issue #13 is the active implementation scope. Complete source recovery, Composer, 30 standalone Hero scenes, Booking Builder, public navigation/About/auth, persistent admin categories, merchandise/direct PPV separation, and Canva clarity. Preserve all locked requirements and update progress documents and issue #13 as implementation lands. Do not advance to 0.9.0 until verified or explicitly deferred by the user.

Actual recovered marketing source is `app/Core/MarketingStore.php`; runtime public routes are under `public/routes`, and operational commands are under `app/Console`. Do not assume historical paths or advanced marketing descriptions exist in executable source.

## Required release gates

At minimum, verify:

- PHP syntax for every runtime/source PHP file
- JavaScript syntax for public scripts
- Migration manifest and declared-table uniqueness
- Publishing state and visibility behavior
- Newsletter confirmation, unsubscribe, segmentation, and suppression
- Promotions, links, referrals, and attribution
- Same-origin analytics and foreign-origin rejection
- Scene scheduling, idempotency, and stale-state prevention
- Installer regression
- Runtime-only package exclusions and clean state
- Repository secret, generated-key, private-data, and unsafe-permission scan

CI must additionally run PHP 8.2/8.3, disposable MySQL apply/verify/rollback, and interactive desktop/mobile browser acceptance. Record exact evidence in status, handoff, roadmap, testing, and test-report documents.

## Public repository safety

Never commit production credentials, generated secrets, payment tokens, raw license keys, signing private keys, private conversations, request/booking data, owner notes, subscriber/customer records, consent exports, identity documents, attachments, runtime logs/sessions/backups, installed add-ons, or private/paid media.

## Truthfulness

Distinguish among locally tested package behavior, CI-prepared behavior, planned work, and real production-service integration. Do not claim source import, MySQL, Playwright, live payments, production email delivery, public licensing SaaS, merge, tag, or release without direct evidence.

## Current priority

The owner reported nine missing static folders blocking the delivered fresh installer. The active correction removes unused scaffolding, checks real static folders without write/mode changes, preserves required runtime writes/ownership repair, and adds a true foreign-owner extracted-ZIP regression plus a one-file stopped-installer repair package. Read the current corrective section in `PROJECT_STATUS.md` and `docs/TEST_REPORT.md`; older green checkpoints do not cover this newly reproduced scenario. Upload this correction and observe its fresh CI before calling it verified.


The 0.8.1 continuation in `INCOMPLETE_WORK.md` is implemented and verified, including the final Composer and Admin timing corrections. Continue from the latest executable branch and the owner's current instructions; preserve its regression gates and upload source/tests/progress as work lands. Do not repeat completed source recovery or treat historical advanced marketing claims as implemented. Read `docs/SOURCE_RECOVERY.md` and report subsequent failures and unavailable gates truthfully. No 0.9.0 implementation is included in this checkpoint.

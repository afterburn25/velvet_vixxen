# Agent Instructions — Velvet Vixxen

## Source of truth

Repository: `afterburn25/velvet_vixxen`

Current integration target: **0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise**

Current executable installer/database/layout correction: `e5b04daa132521be69c943151f8112c7cd2017e8` on the active feature branch. All seven jobs in [run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907) passed and matching customer downloads were saved. Exact current source, verification and delivery status is in `PROJECT_STATUS.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json`. Earlier folder-only checkpoint `eed4c5efe1f3be9aa2522f247adcd2a5e84c509a` predates this repair. Inspect live source, CI, PR and package evidence before claiming completion, merge or deployment.

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

Finish the owner's image-upload, Full Page Preview, Publish and folder-reorganization routing repair together with separate Add Button layers in Visual Composer. Current progress and pending verification are at the top of the six handoff files. The earlier installer source/CI above is the verified starting baseline, not proof of this new repair.

Public implementations stay under `public/routes`. Thin physical compatibility files must preserve every existing public destination, including root/subfolder installations and signed-media URLs. `media.php` is the only additional root PHP entry point permitted by this repair; keep its implementation in the organized source directory and retain the strict root allowlist for all other files. Never replace authentication, CSRF, entitlement or method checks with a permissive forwarding handler.

Require extracted-package routing/upload/preview/publication acceptance without a synthetic missing-file fallback, in addition to the existing HTTP/browser suites. Preserve button/photo/content independence, device layouts, safe URL validation and restorable publications. Keep the new installed-site Media/Composer repair separate from the unfinished-installer repair, and upload executable source, tests, progress, issue #13 and exact download evidence.

## Preserved installer baseline

The user requires setup to continue whether the selected database is empty or already contains compatible application tables. Preserve the implemented resume/schema reconciliation, original owner authentication, keys, identity, rows and saved content; never restore a blanket empty-database refusal or destructive automatic rollback. A pending checkpoint must remain resumable across ordinary HTTP bootstrap and process interruption, and must not count as publicly installed before verification. Recovery and installer retirement share the setup guard.

The same continuation fixes Server/Folders alignment, the extra Folders rule and Password/Confirm Password alignment. Preserve real browser geometry and completion-state checks. The runtime-only database repair is an eight-file overlay and includes the earlier folder fix; it must not contain customer configuration or runtime state. Current source, test and download evidence belongs in the database-repair release record.

Keep all required gates, including actual packaged foreign ownership, real persistent OPcache, packaged existing-database MySQL execution on PHP 8.2/8.3, and real desktop/mobile installer acceptance. No source, test or CI gate may be replaced by a passing skip or ineffective fixture. Continue from the actual executable branch and upload source/tests/progress as work lands. Do not repeat completed recovery or treat historical advanced marketing claims as implemented. No 0.9.0 work is included.

# Agent Instructions — Velvet Vixxen

<!-- installed-build-followup:start -->
## Installed-update repair — Build 20261019.5; final CI pending

**The owner’s affected installation remains unresolved and has not been accessed.** The newest executable source is on `feature/0.8.1-site-ux-hero-library`, continuing from uploaded head `6b2130b6ec6f6d49bf95cab9f9bccaa4fad79f79`. Previous source/CI/download records below are historical and cannot establish that a customer deployment succeeded.

The exact previous saved downloads match their gated artifact. A retained-process negative control now reproduces a missed update failure: every replacement file matches on disk, while PHP OPcache with timestamp validation disabled still executes the earlier Composer and version file. Add Button is absent and Preview/Publish emit no requests. This is a proven laboratory failure mechanism; the owner’s server configuration is unknown.

**Implemented:** explicit build identity in the admin and package filenames; a complete 324-file shared-runtime hash inventory; and active-owner/CSRF-protected `admin/update.php` that diagnoses missing/mixed files and refreshes only this site’s verified PHP. The complete Upgrade contains 325 runtime files including that generated inventory. Missing/mixed contents block activation. Existing configuration, keys, owner records, database state, uploads and other applications’ PHP cache are preserved. The complete Upgrade is the primary update for an installed 0.8.1 site whose exact older build is unknown. The earlier eight-file installer repair is unchanged and is not a new Composer repair.

**Local acceptance passed on final runtime bytes:** all three actual historical-package upgrade cases on PHP 8.3.6 / Chromium 155.0.8059.84: root with persistent cache, nested with persistent cache (including `opcache.enable_cli=0`), and nested with cache genuinely disabled. The same PHP process and authenticated browser survive extraction and the real owner refresh. Both cached cases verify Add Button, preview staying open, persisted publication, the public Booking button, both image upload forms, exact uploaded bytes/browser decoding and zero uncaught JavaScript errors. Owner/member/demoted-owner, CSRF/method, mixed-file blocking, six desktop/mobile status layouts, private-state and unrelated-cache preservation checks passed. The new runtime feature suite passes 25 assertions. Existing source/HTTP and four packaged Composer action suites also passed locally; the broader exact-source PHP 8.2/8.3 and MySQL 8 gates remain mandatory in CI.

A separate four-case Apache/PHP-FPM audit of the prior delivered .4 runtime passed real route/save/publish/upload checks. Removing a physical API under ProxyPassMatch reproduces the exact `File not found.` response. No speculative routing change was added. See `docs/releases/0.8.1-hosted-update-audit.json` for that audit’s distinct scope.

Final new source commit, GitHub CI, gated artifact comparison and replacement download evidence are pending. See `docs/UPDATE_VERIFICATION.md`, `docs/releases/0.8.1-installed-update-acceptance.json` and `docs/releases/0.8.1-build5-package-audit.json`. Do not infer hosted resolution or final 0.8.1 completion from local acceptance. Issue #13 stays open, payments stay sandbox-only, and no 0.9.0 work is included.
<!-- installed-build-followup:end -->

## Source of truth

Repository: `afterburn25/velvet_vixxen`

Current integration target: **0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise**

Preceding executable Media/Composer/public-route source and tests: `db759342e401617c9933c13b81a147ad9f8fd515`, tree `c62be80d9d4a8250905bdb86a1b4f3ea1676c4e7`, on the active feature branch. Runtime implementation was uploaded in `723205b7bddad06d4f603b539aea1a777bdd2ae5`; the final test followup changes no runtime bytes. All seven jobs in [run 39304801698](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698) passed, and the saved customer downloads match the downloaded gated artifact. Exact current evidence is in `PROJECT_STATUS.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-media-composer-repair.json`. The earlier installer/database/layout correction `e5b04daa132521be69c943151f8112c7cd2017e8` remains included in full distributions. Inspect live source, CI, PR and package evidence before claiming a subsequent completion, merge or deployment.

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

Preserve the verified image-upload, Full Page Preview, Publish, folder-reorganization routing and separate Add Button layers. The prior source and packaged tests passed, but the owner subsequently reported an unchanged installation. Keep that report open until the actual hosted build and behavior are verified; previous CI cannot establish deployment. The current source/CI/artifact/download evidence is at the top of the six handoff files. Continue the owner's next requested work from this actual executable branch.

Public implementations stay under `public/routes`. Thin physical compatibility files must preserve every existing public destination, including root/subfolder installations and signed-media URLs. `media.php` is the only additional root PHP entry point permitted by this repair; keep its implementation in the organized source directory and retain the strict root allowlist for all other files. Never replace authentication, CSRF, entitlement or method checks with a permissive forwarding handler.

Require extracted-package routing/upload/preview/publication acceptance without a synthetic missing-file fallback, in addition to the existing HTTP/browser suites. Preserve button/photo/content independence, device layouts, safe URL validation and restorable publications. Keep the new installed-site Media/Composer repair separate from the unfinished-installer repair, and upload executable source, tests, progress, issue #13 and exact download evidence.

## Preserved installer baseline

The user requires setup to continue whether the selected database is empty or already contains compatible application tables. Preserve the implemented resume/schema reconciliation, original owner authentication, keys, identity, rows and saved content; never restore a blanket empty-database refusal or destructive automatic rollback. A pending checkpoint must remain resumable across ordinary HTTP bootstrap and process interruption, and must not count as publicly installed before verification. Recovery and installer retirement share the setup guard.

The same continuation fixes Server/Folders alignment, the extra Folders rule and Password/Confirm Password alignment. Preserve real browser geometry and completion-state checks. The runtime-only database repair is an eight-file overlay and includes the earlier folder fix; it must not contain customer configuration or runtime state. Current source, test and download evidence belongs in the database-repair release record.

Keep all required gates, including actual packaged foreign ownership, real persistent OPcache, packaged existing-database MySQL execution on PHP 8.2/8.3, and real desktop/mobile installer acceptance. No source, test or CI gate may be replaced by a passing skip or ineffective fixture. Continue from the actual executable branch and upload source/tests/progress as work lands. Do not repeat completed recovery or treat historical advanced marketing claims as implemented. No 0.9.0 work is included.

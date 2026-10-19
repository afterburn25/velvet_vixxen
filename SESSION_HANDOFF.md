# Session Handoff — Velvet Vixxen

Updated: 2026-10-19. Repository: `afterburn25/velvet_vixxen`.

## Start from executable source

- Active branch: **`feature/0.8.1-site-ux-hero-library`**.
- Target: **0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise**. Do not advance to 0.9.0.
- Source recovery commit: [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23).
- Review: [PR #15](https://github.com/afterburn25/velvet_vixxen/pull/15). Completion: [issue #13](https://github.com/afterburn25/velvet_vixxen/issues/13). Related source/release/recovery issues: #1, #3, #11, #12.
- Input: checksum-verified 0.8.1 fresh package, SHA-256 `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`; separate 0.7.4 Composer restoration, then actual 0.8.1 feature implementation.

Read all six handoff files and inspect the live branch, current commit, open issues, tests and artifacts. The old handoff/marketing branches were documentation-only; this implementation branch contains the application. Preserve executable source in GitHub after every session.

## Work now present

Composer direct editing, all-edge clipping/extreme resizing, movable live text/button group, independent device backgrounds/layers, 30 standalone luxury images and thumbnails, searchable library, Booking Builder with immutable submission snapshots, shared sticky public navigation, About Me and editor, centered auth/recovery pages, persistent grouped admin navigation, merchandise/variants/stock/shipping/fulfillment, direct PPV/rental and separate membership checkout, manual Canva bridge, and owner/CLI installed database updates.

Current migrations: **14 / 82 declared tables**. The updater safely applies 013/014 to a prior 12-migration site and never reinstalls or replaces identity/configuration. See `docs/UPGRADING.md`, `docs/VISUAL_COMPOSER.md`, `docs/BOOKING_BUILDER.md`, `docs/MERCHANDISE.md` and `docs/CANVA_WORKFLOW.md`.

## Current evidence and next concrete work

Focused source/HTTP/browser/SQL tests pass for the new features. The real 30-scene library test decoded all 60 images and published/rendered all scenes at three widths. About/admin/account layouts pass desktop/tablet/mobile. Booking and merchandise acceptance passes, including historical submissions, private addresses, stale stock edits and revoked PPV entitlements. Installed updater preservation/failure/locking checks pass locally.

Baseline GitHub run [39207173912](https://github.com/afterburn25/velvet_vixxen/actions/runs/39207173912) passed PHP 8.2/8.3, MySQL 8 baseline migrations and HTTP, but failed an old age-gate browser setup. That setup is corrected. The feature checkpoint needs the combined stable suite and its own PHP 8.2/8.3, MySQL 8, HTTP/browser and package CI results. Do not present the earlier green individual jobs as a complete feature release.

Finish the current release gates in `docs/TEST_REPORT.md`, fix observed failures, build/check runtime-only fresh and upgrade ZIPs from committed source, record their checksums, and upload the latest source/tests/docs. Keep issue #13 updated with real commit/run links. Only then mark the verified 0.8.1 scope complete.

## Test discipline

Run `bash scripts/test.sh`, `bash scripts/test_http.sh`, `VELVET_BROWSER_TESTS=1 bash scripts/test_http.sh`, and `php tests/Database/migrations_mysql.php` with disposable local database credentials. The HTTP harness snapshots itself and the runtime before running; no active shared-file edit can change a long-running Bash suite. Browser execution must actually launch Chromium.

The local environment used PHP 8.3.6, MariaDB 10.11.14 and Chrome for Testing 155.0.8059.84. **MariaDB is not MySQL 8 evidence.** GitHub provides the actual MySQL 8 service. Never seed a demo inside the authoritative checkout or a real site: installed bootstrap may retire `/install`. Never commit test-installed state or credentials.

## Requirements to preserve

Approved pink/magenta/black design; no new mockups or placeholder people; owner transparent photos remain separate; one-page installer; no installation key; 12-character minimum owner/member passwords; safe 0755/0775 only; organized folders; runtime-only customer ZIPs; sandbox payments; no credentials, customer records, private conversations/attachments, identity records, payment secrets, raw licenses, private signing keys, logs, sessions or paid/private media in GitHub.

Upgrade payloads exclude config/storage/uploads/install. Recovery preserves valid active identity and unique/unknown backup data. Database updates require a reviewed plan and operator-confirmed backup, use filesystem/database locks, and stop without automatic rollback if DDL partially fails.

Actual owner photographs and real catalog/biography content are not available in source. Do not invent them. Historical advanced marketing claims remain unreconciled implementation gaps in `docs/SOURCE_RECOVERY.md`; production email/live payments/hosting certification remain outside this verified development milestone.

## Verification correction checkpoint

Correction commit [`1bb7f1903ac01252f22a5a564f18764998781678`](https://github.com/afterburn25/velvet_vixxen/commit/1bb7f1903ac01252f22a5a564f18764998781678), GitHub run [39211611678](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678), verified PHP 8.2.34/8.3.35, MySQL 8.0.46 (14 migrations / 82 tables, 32 installed-upgrade checks, 12 booking checks, merchandise transactions and complete rollback), all seven HTTP suites and all three Composer suites on CI Chromium 151. The 30-scene library completed all 90 published renders. The prior SQL privilege and Composer focus failures are fixed and verified.

That run then exposed an Admin category-state race during immediate refresh; the gated package job was skipped. The current correction saves native category-state changes before the next browser task, flushes pending changes on page exit, respects an explicitly collapsed active category and checks immediate refresh plus keyboard activation. A complete green run and final committed-source ZIP checksums are still required. Detailed failure history remains in `docs/TEST_REPORT.md`.

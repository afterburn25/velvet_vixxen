# Session Handoff — Velvet Vixxen

## Installer folder correction delivered — 2026-10-19

The owner reported fresh-install readiness failures for nine missing image, font/icon and template folders after downloading the earlier 0.8.1 package. The manifest required unused empty scaffolding that the runtime-only ZIP intentionally does not contain. On panel-owned read-only asset trees, PHP cannot create those folders. The earlier same-owner installer fixtures did not cover this extracted-package ownership case; the prior green runs below do not certify it.

The current correction removes those nine unused entries and unused `database/seeds`, checks actual packaged application/asset directories for presence/readability without creating or chmodding them, and preserves the existing writable-runtime probes and limited ownership repair. Missing real application folders remain blocking errors. The delivered 6,400-byte one-file installer repair ZIP lets an already-uploaded unfinished installation resume without replacing configuration, storage, uploads or database data. The corrected complete fresh package contains the same service. Both corrected downloads and the separate package manifest have been saved; the ordinary upgrade package is unchanged because it excludes the installer.

Local PHP 8.3.6 source/JavaScript syntax/security/package suites passed. The new real foreign-owner extracted-ZIP regression reproduced exactly the nine original errors, and the corrected service passed repeated probes, allowed ownership repair, required-write failures, missing real assets and failed-repair preservation. Corrective source, tests and progress documents are uploaded at [`776450964e5a1e9d26719d886d114ce3eef4037e`](https://github.com/afterburn25/velvet_vixxen/commit/776450964e5a1e9d26719d886d114ce3eef4037e) on `feature/0.8.1-site-ux-hero-library`. Fresh full CI [39278836707](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278836707) and [39278828940](https://github.com/afterburn25/velvet_vixxen/actions/runs/39278828940) remained queued as observed at 15:43 UTC; PHP 8.2/8.3, MySQL 8 and HTTP/browser success for this corrective commit is not yet claimed. The 0.8.1 features and locked requirements remain the scope; no 0.9.0 work is authorized by this correction.

Current correction evidence and all three package hashes are in `docs/releases/0.8.1-installer-folder-repair.json` and `RELEASE_MANIFEST.txt` (use paths relative to the repository root). Follow `docs/INSTALLER_FOLDER_REPAIR.md` to apply the one-file repair to the existing unfinished installation and click **Run Checks**. The earlier feature checkpoints below retain their original source, run and package hashes; their prior fresh-install ZIP is superseded.

Updated: 2026-10-19. Repository: `afterburn25/velvet_vixxen`.

## Earlier verified continuation — before the installer correction

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

Read `INCOMPLETE_WORK.md`, `SESSION_HANDOFF.md`, `PROJECT_STATUS.md`, `docs/ROADMAP.md`, `docs/TEST_REPORT.md` and `NEXT_SESSION_PROMPT.md` in full. Inspect the live branch, current commit, [PR #15](https://github.com/afterburn25/velvet_vixxen/pull/15), [issue #13](https://github.com/afterburn25/velvet_vixxen/issues/13), source-import issue #1 and release-gate issue #3. The original handoff and marketing branches were documentation-only. Do not replace the newer executable branch with those older records or assume main has been merged.

## Delivered scope

Composer direct dragging/extreme resizing/all-edge clipping and lower-edge crop; movable live text/button group; independent device layouts/background appearance; 30 individual luxury backgrounds and thumbnails; searchable library/drafts/publication/history; Booking Builder and immutable inquiry snapshots; shared sticky public navigation; About Me/editor; centered authentication/recovery pages; eight persistent Admin categories; merchandise/images/variants/stock/shipping/fulfillment; separate membership and direct PPV/rental checkout; manual Canva bridge; safe installed database updates.

The earlier Composer/Admin correction preserves Composer handle nodes/focus, makes the MySQL rollback fixture work without elevated privileges, corrects About spacing/empty details layout, and saves native Admin category changes before the next browser task. Its meaningful timing regression fails against the pre-fix script and passes the exact final CI engine.

## Earlier feature evidence and customer packages

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

Actual MySQL 8.0.46 CI verifies 14 migrations / 82 tables and the prior 12→14 installed-site upgrade. All seven HTTP and twelve actual Chromium browser suites pass in the same workflow. Local PHP 8.3.6/MariaDB 10.11.14/Chrome 155 results remain supplementary; MariaDB is never substituted for MySQL 8 evidence.

## Continue without losing work

The requested 0.8.1 implementation and acceptance checklist are complete. Start subsequent work from the live implementation branch and the owner's current instructions. Preserve source/tests/docs in GitHub as work lands. Inspect PR merge and artifact state before describing either; this handoff records a verified feature branch and packages, not an unobserved deployment. 0.9.0 has not been started.

Owner workflows are in `docs/VISUAL_COMPOSER.md`, `docs/BOOKING_BUILDER.md`, `docs/MERCHANDISE.md`, `docs/CANVA_WORKFLOW.md` and `docs/UPGRADING.md`. Existing database sites use System → Database Updates after a verified backup; never rerun the fresh installer over them. File-storage mode reports no SQL migration needed.

## Requirements to preserve

Approved neon-pink/magenta/black design; no new mockups or placeholder people; owner transparent photos remain separate; one-page installer; no installation key; 12-character minimum owner/member passwords; safe 0755/0775, never 0777; organized folders; runtime-only customer ZIPs; sandbox payments. Never commit credentials, customer/member/booking records, private conversations/attachments, identity records, payment secrets, raw license keys, signing private keys, logs, sessions, runtime state or private/paid media.

Test only marked disposable runtime copies/databases. Commands are in `docs/TESTING.md`. Never seed a demo in the source checkout or a real site. Upgrade packages omit config/storage/uploads/install. Recovery preserves valid active identity and unique/unknown backups; installed updates use reviewed plans, locks and operator backup acknowledgment.

Actual owner photos, biography/statistics and catalog content have not been invented. Historical advanced marketing automation/audiences/attribution/retention/MySQL-persistence claims remain unverified gaps in `docs/SOURCE_RECOVERY.md`. Production email delivery, live payments and representative hosting certification remain outside this sandbox milestone.

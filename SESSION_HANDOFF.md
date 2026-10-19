# Session Handoff — Velvet Vixxen

## Installer folder and PHP cache correction — 2026-10-19

The nine reported missing static-folder failures were reproduced against the exact previous fresh ZIP with PHP running as a different unprivileged user. Runtime correction `776450964e5a1e9d26719d886d114ce3eef4037e` removes unused directory requirements, verifies real packaged assets without mkdir/chmod, and preserves genuine runtime writes and limited ownership repair. The older same-owner fixtures did not cover the failure.

A subsequent real persistent-PHP test found that timestamp-preserving ZIP extraction could leave the old installer cached in OPcache even after the corrected bytes were uploaded. The repaired service now has deterministic ZIP timestamp **2026-10-19 00:00:00** in both fresh and repair packages. Only that entry's timestamp changes; corrected PHP bytes, all other file metadata and the standard upgrade are unchanged. The new mandatory OPcache regression proves stale execution for three same-timestamp requests, then loads the corrected manifest immediately after extracting the real repair ZIP, without restarting PHP or explicitly invalidating its cache.

Final implementation and package source: [`eed4c5efe1f3be9aa2522f247adcd2a5e84c509a`](https://github.com/afterburn25/velvet_vixxen/commit/eed4c5efe1f3be9aa2522f247adcd2a5e84c509a), tree `a0be19e15f58d4576d78e1b5a820317f5242acab`. The integrated local PHP 8.3.6 suite **passed**. Both final GitHub runs, [PR 39283445755](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283445755) and [push 39283438479](https://github.com/afterburn25/velvet_vixxen/actions/runs/39283438479), have complete successful archived execution logs for the repository/source contract, PHP 8.2.34/8.3.35 source and package suites (including both ownership and OPcache regressions), MySQL 8.0.46 apply/verify/rollback, seven HTTP suites, twelve Chromium browser suites, and runtime packaging. Both CI package builds match the corrected download hashes. At 16:21:51 UTC, GitHub's run/job metadata still reported `queued` with null conclusions despite those completed logs and uploaded artifacts; successful execution is established from the logs, and an official successful workflow conclusion is not claimed. Exact jobs, checkout provenance and artifacts are recorded in `docs/TEST_REPORT.md`.

The timestamp-aware repair ZIP, fresh ZIP and separate checksum manifest were successfully saved as replacement downloads. The repair ZIP is 6,400 bytes and contains only `install/services/FileSystemService.php`; the fresh ZIP has 313 runtime files. Current hashes are in `docs/releases/0.8.1-installer-folder-repair.json` and `RELEASE_MANIFEST.txt` (paths relative to the repository root). Follow `docs/INSTALLER_FOLDER_REPAIR.md` to resume the existing unfinished installer and click **Run Checks**. Earlier fresh/repair packages are superseded. Preserve all locked requirements; no 0.9.0 work is included.

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

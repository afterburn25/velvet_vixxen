# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.6.0 — Add-on Framework**

The clean packaged working tree contains the approved site, Visual Composer, Canva workflow, Media Center, members and entitlements, sandbox subscriptions/PPV commerce, and a complete local add-on lifecycle.

Repository: `afterburn25/velvet_vixxen`

## 0.6.0 completed in the tested package

- ZIP upload and permission/compatibility review before code executes
- Manifest schema, semantic versions, core/PHP ranges, dependencies, and conflicts
- File checksums and optional Ed25519 signatures
- Package path, duplicate-entry, private-key, environment-file, file-count, size, and blocked-content checks
- Install, activate, deactivate, update, rollback, preserve-data uninstall, and purge uninstall
- Add-on-owned migrations and migration registry
- Pre-change backups and failed-change restoration
- Boot-failure quarantine and global safe mode
- Admin pages/navigation, public routes, widgets, events, filters, services, namespaced settings, and scheduled tasks
- Core member/commerce events and `commerce.quote` filter
- Homepage widget locations and protected public route dispatcher
- Free, trial, and signed offline-license foundations
- Add-on Manager, lifecycle logs, diagnostics, developer guide, schema guide, and starter add-on
- Ninth migration; 48 declared application tables

## Local evidence

- 121 PHP files passed syntax validation
- 6 public JavaScript files passed syntax validation
- 7 Python HTTP/Playwright files passed syntax validation
- `bash scripts/test.sh` passed
- `bash scripts/test_http.sh` passed member, commerce, and add-on acceptance
- Extracted release package passed source tests, HTTP acceptance, cleanup, repository safety, and checksum verification
- Package SHA-256: `8462746ee90ec0ec054e756e92afc63a9fc90e9f47f6bea39e7b0a6a156f065c`

## Environmental limits

- Local PDO MySQL/MySQL was unavailable. Live DDL execution is not claimed; MySQL 8 CI must apply all nine migrations, verify 48 tables, exercise an add-on-owned migration, roll everything back, and leave the test database empty.
- Local Chromium navigation returned `net::ERR_BLOCKED_BY_ADMINISTRATOR`. The policy was not bypassed. Four Playwright suites remain CI-owned.
- The payment adapter is sandbox-only.
- A hosted license service, online activation, customer downloads, remote update feed, and marketplace are not part of 0.6.0.

## Repository truth boundary

The packaged 0.6.0 source is complete locally, but the complete executable tree is not yet imported into this GitHub branch. Issue #1 tracks source import. Issue #7 tracks 0.6.0 delivery and required release gates. This branch may document the package without claiming full source CI, MySQL, Playwright, merge, tag, or release evidence that has not happened.

## Next actions

1. Import the clean 0.6.0 source into `feature/0.6.0-addon-framework`.
2. Run PHP 8.2/8.3, MySQL 8, HTTP, task, repository-safety, and four Playwright jobs.
3. Correct CI-only findings and record exact commit/workflow evidence.
4. Merge only after required checks are green.
5. Begin `0.6.1 — Commercial Licensing & Remote Updates`.

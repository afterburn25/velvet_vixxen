# Velvet Vixxen 0.8.1 — Current Test Evidence

Date: 2026-10-19. Active branch: `feature/0.8.1-site-ux-hero-library`. Tracking: #13, #1 and #3.

## Release boundary

**0.8.1 is in development, not complete.** This report replaces historical pass claims that could not be reproduced from GitHub source. The first checkpoint imports the checksum-verified 0.8.1 executable baseline, selectively restores 0.7.4 Composer fixes, corrects recovery/backup safety, restores engineering tests and enables mandatory CI.

Baseline archive SHA-256: `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`. Provenance and mismatched historical claims: `SOURCE_RECOVERY.md`.

## Observed local evidence

| Gate | Actual result |
| --- | --- |
| PHP 8.3.6 source suite | PASS: PHP/JavaScript/Python syntax, smoke, installer, media, members, entitlements, sandbox commerce, add-ons, licensing, communication and basic marketing regressions. |
| Recovery fixtures | PASS: 72 assertions for matching identities, preservation, ambiguity, linked paths and executing installer behavior. |
| Repair-backup fixtures | PASS: 71 assertions for verified duplicates, unique/unknown data preservation, path boundaries and archive handling. |
| Runtime packaging | PASS: fresh/upgrade ZIPs, exact exclusions, deterministic output, unchanged existing config/lock/private data/uploads after overlay, symlink rejection. |
| MariaDB 10.11.14 migrations | PASS: all 12 migrations create 78 tables; repeated apply is idempotent; rollback leaves the disposable database empty. This is not MySQL 8 evidence. |
| Installed HTTP | PASS: member/entitlement, sandbox commerce, add-on lifecycle, communication ownership and CSRF, newsletter pending-state/token privacy, same-origin analytics with foreign/malformed/missing origin rejection. |
| Recovered Composer real Chromium | PASS twice: continuous drag, corner resize, 0.5–400% save/reload, live text/button box movement, distinct desktop/tablet/mobile arrangements, background pan/undo/redo, draft isolation, Hero/Footer publication and bundled/uploaded image loading. |
| Complete baseline browser regression | In progress; favicon 404 discovered and corrected. |
| PHP 8.2 source suite | Pending actual GitHub CI execution. |
| MySQL 8 apply/verify/rollback | Pending actual GitHub CI execution. |
| New 0.8.1 feature acceptance | Pending implementation and testing; see INCOMPLETE_WORK.md. |

The source and HTTP harnesses create marked disposable copies. They do not seed or retire the installer inside the engineering checkout or a real site. Chrome for Testing 155.0.8059.84 ran actual local pages; screenshot-only reconstructions are not counted as interaction tests.

## Commands used

```bash
bash scripts/test.sh
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
php tests/Database/migrations_mysql.php
VELVET_TEST_SUITE=tests/Browser/composer_recovery_playwright.py \
VELVET_TEST_PORT=8770 bash scripts/test_http.sh
```

Local tools were isolated PHP 8.3.6, MariaDB 10.11.14, Python/Playwright and Chrome. Database credentials and installed fixture state are outside source control. CI declares separate PHP 8.2/8.3, MySQL 8, HTTP/browser and runtime-package jobs; configuration is not a claim those jobs have passed.

## Remaining completion requirements

All Composer/off-canvas/background-image acceptance, 30 standalone scene selection, Booking Builder fields and validation/history, sticky public navigation, centered auth pages, About Me/editor, admin persistence, merchandise/direct PPV purchase and regressions in `INCOMPLETE_WORK.md` must pass. Zero broken Composer/Hero/Footer images, repository secret/safety checks and runtime-only package exclusions remain required.

Payments remain sandbox-only. Production mail delivery and representative shared-hosting certification are not claimed.

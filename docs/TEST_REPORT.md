# Velvet Vixxen 0.8.1 — Current Test Evidence

Updated: 2026-10-19. Branch: `feature/0.8.1-site-ux-hero-library`. Tracking: #13, #1, #3; PR #15.

## Truth boundary

**The 0.8.1 features are implemented; final combined release verification is in progress.** This report records observed execution, not inferred success from suite filenames or workflow configuration. Historical recovery evidence and feature evidence remain distinct.

Recovered source commit: [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23). Input archive SHA-256: `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`. See `SOURCE_RECOVERY.md`.

## Actual baseline GitHub execution

Run [39207173912](https://github.com/afterburn25/velvet_vixxen/actions/runs/39207173912) on the recovery commit:

| Job | Observed result |
| --- | --- |
| Repository/source contract | PASS |
| PHP 8.2 source and package | PASS |
| PHP 8.3 source and package | PASS |
| MySQL 8 apply/reapply/verify/rollback | PASS: baseline 12 migrations / 78 tables |
| Installed HTTP | PASS step |
| Browser regression | FAILED: old member test tried Sign In before dismissing age gate; fixed in the feature tree |
| Final package artifact job | SKIPPED because browser dependency failed |

This is not an all-green run and does not verify the subsequent feature migrations or browser additions.

## Feature checkpoint CI and corrections

Implementation commit [`d81513da5369d000a484331ea6a1000f80bc81a7`](https://github.com/afterburn25/velvet_vixxen/commit/d81513da5369d000a484331ea6a1000f80bc81a7), run [39210406490](https://github.com/afterburn25/velvet_vixxen/actions/runs/39210406490): repository contract, PHP 8.2, PHP 8.3 and all 7 installed HTTP suites passed. MySQL 8.0.46 passed all 32 installed-upgrade checks and merchandise transactions, then failed because the booking rollback fixture used a trigger requiring SUPER under binary logging. The fixture now temporarily renames the disposable messages table, requires the exact missing-table SQL error, restores it and checks all six row counts. No global database privilege or production behavior was changed.

The same CI browser job passed its first seven suites, then exposed Composer handle replacement during a canvas redraw. The correction keeps selected handle nodes stable while updating geometry, preserving keyboard focus as well as the resize interaction. The corrected Composer recovery/focus and expanded controls suites pass locally. The new focus assertion fails against the original d81513 renderer at the expected image-load focus loss, demonstrating a meaningful regression. A subsequent exact-engine/full browser CI run is required; this run's failures are retained as evidence rather than hidden.

Visual screenshot review also corrected About page side spacing and hides an empty details column when no photo or statistics have been supplied. The updated desktop/tablet/mobile About/account/admin layout suite passes. Asset build identity is now `0.8.1-20261019.2`; core VERSION remains 0.8.1.

## Observed feature implementation evidence

| Area | Actual local execution |
| --- | --- |
| PHP 8.3 source and syntax | PASS, clean exit 0 on the stable final implementation: PHP/JS/Python syntax and restored feature/security/installer/package suites. Log: final-run/source.log in execution evidence. |
| Installer recovery | PASS: 72 focused assertions for matching identity, active configuration/key preservation, ambiguous backups, linked paths and real installer execution. |
| Repair backups | PASS: 71 assertions covering verified duplicates, unique/unknown/unreadable data preservation, path confinement and safe deletion/archive handling. |
| Runtime package tests | PASS: reproducibility, runtime-only exclusions, fresh uninstalled state, upgrade overlay preserving config/lock/private data/uploads byte-for-byte and symlink rejection. |
| Composer controls | PASS in real Chromium: direct movement, 0.5–400% resizing, coordinates −400% to 500%, reachable handles, aspect ratio, all-edge clipping, lower 20% crop, live text/button geometry, background pan/appearance, device isolation, snapping/keyboard/numeric controls, undo/redo, hide/lock/order/duplicate/delete, draft preview and history rollback. |
| Composer security/image paths | PASS: protected original exclusion, safe embedded scene titles, leading-zero version IDs, negative-z photo visibility, bundled/uploaded asset decoding. |
| Hero Library | PASS: 30 distinct full images + 30 thumbnails decode; search/categories/placement and draft selection; all 30 scenes published and rendered at desktop/tablet/mobile, **90/90 public renders**. |
| Booking Builder | PASS: 54 source assertions; actual owner CRUD/field validation/choice/phone browser acceptance; HTTP CSRF/nonowner/malformed email/phone/choices/rate limit/upload privacy/history; 12 SQL transaction checks preserve snapshots and roll back failed writes. |
| About Me and admin/public UX | PASS: 21 source assertions; real owner edits, escaping and CSRF, hidden empty stats, persistent categories/active page, mobile menu, centered login/register/recovery/Join, sticky navigation at 1440/834/390 widths. Separate public layout audit passes 12 routes at desktop/mobile. |
| Merchandise | PASS: 45 source assertions; desktop/mobile owner image/variant editing, live pricing, cart quantities, address/shipping validation, sandbox checkout, fulfillment/tracking, stock reservation/restoration, stale-editor rejection, PPV/cart isolation, pending-charge reconciliation and refund/chargeback revocation. |
| Installed Database Updates | PASS: 13 source checks, owner/CSRF/private-path HTTP coverage, 32 MariaDB integration checks including actual 12→14 upgrade, existing booking/configuration/lock/key/upload preservation, no automatic down migration on injected DDL failure, reviewed retry, stale plan, locks, symlinks/permissions and real CLI. |
| MariaDB 10.11.14 full schema | PASS: 14 migrations / 82 declared tables; apply, idempotent reapply, merchandise/booking/updater scenarios, rollback to empty disposable schema. This is not MySQL 8 evidence. |
| Installed HTTP regression | PASS, all seven suites in one stable aggregate (exit 0): members, commerce, add-ons, current platform, booking builder, merchandise and database updates. Log: final-run/http.log. Feature GitHub CI remains required. |
| Browser regression | PASS, all 12 real Chromium suites in one stable aggregate (exit 0), including the full 30-scene library/90 public renders, Composer controls, booking/merchandise and desktop/tablet/mobile layouts. Log: final-run/browser.log. A subsequently discovered transient handle replacement has its own correction/regression before the next CI run. |

The long first library run printed full success, then its Bash wrapper exited after a concurrent harness edit. The wrapper now execs its disposable snapshot and deduplicates its suite list. The stable aggregate must finish cleanly; that earlier outer exit is not called a pass.

The final independent asset/installer audit checked 55 public/admin pages, found and corrected one caption thumbnail issue, then rechecked 7 pages/29 unique local assets with no broken images or resource errors. All loaded CSS/JS have exactly one current asset build version. Repository safety, expanded credential/private-key signatures, 82 directory permissions, 47 literal asset references and 139 default-data asset references passed.

## Commands and isolation

```bash
bash scripts/test.sh
bash scripts/test_http.sh
VELVET_BROWSER_TESTS=1 bash scripts/test_http.sh
php tests/Database/migrations_mysql.php
python3 scripts/package.py --output artifacts/runtime
```

For a focused browser scenario, set `VELVET_TEST_SUITE=tests/Browser/site_ux_playwright.py`. Set `VELVET_SCREENSHOT_DIR` to collect actual execution screenshots outside customer packages. Full configuration is in `TESTING.md`.

Local tooling: PHP 8.3.6 with PDO MySQL, MariaDB 10.11.14, Python Playwright and Chrome for Testing 155.0.8059.84. Source/HTTP tests create marked disposable runtime copies; SQL tests require a disposable database. No demo is installed in the authoritative checkout. Fixture credentials and private runtime state are not release contents.

## Remaining release evidence

- Stable combined source, all 7 HTTP and all 12 browser suites have passed. Verify the subsequent CI-specific SQL fixture and persistent-handle correction with focused local tests and actual GitHub CI.
- Actual feature GitHub PHP 8.2/8.3, MySQL 8 apply/reapply/rollback with new booking/merchandise/updater scenarios, HTTP/browser, repository/secret and package jobs.
- Final runtime-only fresh/upgrade ZIP hashes and artifact links tied to the committed source.
- Updated issue #13, progress documents and source uploaded after final fixes.

Payments remain sandbox-only. Owner photographs/catalog content, production mail delivery and representative shared-hosting certification are not claimed. Filesystem ownership/write regressions are reproducible local/CI fixtures, not a test of the owner's actual hosting account.

# Velvet Vixxen 0.8.1 — Current Test Evidence

## Installer folder correction in progress — 2026-10-19

The owner reported fresh-install readiness failures for nine missing image, font/icon and template folders after downloading the earlier 0.8.1 package. The manifest required unused empty scaffolding that the runtime-only ZIP intentionally does not contain. On panel-owned read-only asset trees, PHP cannot create those folders. The earlier same-owner installer fixtures did not cover this extracted-package ownership case; the prior green runs below do not certify it.

The current correction removes those nine unused entries and unused `database/seeds`, checks actual packaged application/asset directories for presence/readability without creating or chmodding them, and preserves the existing writable-runtime probes and limited ownership repair. Missing real application folders remain blocking errors. A one-file installer repair ZIP will let an already-uploaded unfinished installation resume without replacing configuration, storage, uploads or database data. The complete fresh package will also contain the correction.

Local PHP 8.3.6 source/JavaScript syntax/security/package suites passed. The new real foreign-owner extracted-ZIP regression reproduced exactly the nine original errors, and the corrected service passed repeated probes, allowed ownership repair, required-write failures, missing real assets and failed-repair preservation. Fresh PHP 8.2/8.3, MySQL 8 and HTTP/browser CI is pending on the corrective commit; do not claim that new run has passed until its results are observed. The 0.8.1 features and locked requirements remain the scope; no 0.9.0 work is authorized by this correction.

Updated: 2026-10-19. Branch: `feature/0.8.1-site-ux-hero-library`. Tracking: #13, #1, #3; PR #15.

## Installer correction — observed local evidence

- `bash scripts/test.sh`: **PASS**, PHP 8.3.6; complete source/JS/Python syntax, installer/recovery/feature/security and all three runtime-package regressions. Run log: `/tmp/vvx-installer-folder-source.log` in the disposable execution environment.
- `python3 tests/Installer/packaged_preflight.py`: **PASS**, real packaged source with static trees owned by UID 0 and PHP probes running as UID 65534, using 0755 directories/0644 packaged files. Initial probe plus two rechecks preserve static hashes/modes/owners. All five genuine writable-root failures remain blocking. All five permitted roots can be ownership-repaired while preserving sentinels. Missing `database/migrations` causes repair failure and restores the original database inode, bytes, modes and owner; missing real Hero/CSS/Canva directories remain blocking without empty substitutes.
- Captured prior `FileSystemService.php` SHA-256 `dce5c92af42aedadb774d6ec2f216e4acb75c1b6976c8352a8ca45aa20750d2c` with `--legacy-filesystem` rebuilds the earlier fresh ZIP SHA-256 `4f2e22ce4eb153a89b42180e15ee55ad7259f39712864e3f79756a47d0e27080` byte-for-byte. It reproduces **exactly all nine customer failures**, including message and `----` mode, for all three requests.
- Corrected fresh ZIP SHA-256: `8b60a3142406471c0ab03ed68a298a806832132f46e9ad01b179b01ccaa1e46f`; 313 runtime files. Standard upgrade payload is unchanged because it excludes the installer.
- `python3 tests/Packaging/runtime_packages.py`: **PASS**, reproducible fresh/upgrade/one-file repair ZIPs, exact repair scope and fresh-service byte identity, safe regular-file modes, private/development exclusions, and all other stopped-install/installed-state bytes preserved by overlays.
- Environment limitation recorded honestly: the default local namespace maps only UID 0, so its first attempted ownership fixture failed. Authorized execution outside that namespace supplied real separate ownership. The test has no ineffective same-owner fallback or passing skip; Ubuntu CI uses passwordless sudo only to orchestrate its disposable fixture and drops privileges for every PHP probe.

The direct failure reproduction closes the test gap in the earlier green checkpoints below. It does not claim access to or deployment on the owner's actual host. See `INSTALLER_FOLDER_REPAIR.md` for the small repair overlay and new-install instructions.

## Truth boundary

**The 0.8.1 user-facing features and required engineering release gates are implemented and verified.** This report records observed execution, not inferred success from suite filenames or workflow configuration. Historical recovery evidence and feature evidence remain distinct.

Recovered source commit: [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23). Input archive SHA-256: `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`. See `SOURCE_RECOVERY.md`.

## Complete final GitHub verification

Exact runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). Source tree: `f9ad6dd825def2b525ce0992c1fb9a010344c8b9`. Asset build identity: `0.8.1-20261019.3`. GitHub run [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) completed successfully; all six required jobs passed.

| Job | Exact GitHub job | Result |
| --- | --- | --- |
| Repository and executable source contract | [117930817942](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930817942) | PASS |
| Installed HTTP and Chromium desktop/mobile acceptance | [117930918482](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918482) | PASS |
| MySQL 8 installed upgrades, merchandise and booking transactions | [117930918487](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918487) | PASS |
| PHP 8.2 source and package regressions | [117930918510](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918510) | PASS |
| PHP 8.3 source and package regressions | [117930918540](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117930918540) | PASS |
| Build verified fresh-install and upgrade ZIPs | [117932968594](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/job/117932968594) | PASS |

The source matrix ran PHP 8.2.34 and 8.3.35. MySQL 8.0.46 passed all 14 migrations / 82 tables, idempotent reapply, 32 installed-upgrade checks, 12 booking snapshot/rollback checks, merchandise transaction scenarios and complete rollback. All seven installed HTTP suites and all twelve real Chromium browser suites passed in one stable workflow, including the corrected immediate-refresh Admin behavior. The 30 full scene images and 30 thumbnails decoded, and all 90 published desktop/tablet/mobile renders succeeded.

### Verified artifacts and package hashes

- [velvet-vixxen-runtime-packages](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/artifacts/12168746591), artifact `12168746591`, 14,974,841 bytes, SHA-256 `947bce5ffa3a1369debb6c7885253c5edf694ff2bc5998f29d4e1409b54a0251`.
- [chromium-browser-evidence](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477/artifacts/12168269245), artifact `12168269245`, 48,414,908 bytes, SHA-256 `47b8e5cd4f0f82a7b49e8ccd815a80b45b1df1729e312493303fc644a9e9749c`.

Both customer ZIPs were rebuilt locally from the exact clean committed runtime; their SHA-256 values match the gated GitHub package build. Counts refer to the customer ZIP entries, not engineering source files.

| Package | Bytes | Files | PHP | JavaScript | SHA-256 |
| --- | ---: | ---: | ---: | ---: | --- |
| fresh | 7,576,019 | 313 | 166 | 10 | `4f2e22ce4eb153a89b42180e15ee55ad7259f39712864e3f79756a47d0e27080` |
| upgrade | 7,423,931 | 272 | 155 | 10 | `57b2497758ba9ad24e89a597411f4e16017bfdc74caf141b3e5ecadc4db61715` |

Fresh installation starts uninstalled. Upgrade excludes config/storage/uploads/install and preserves existing private state. Engineering documents, tests, SDK source, workflows, screenshots and artwork provenance/masters stay outside customer ZIPs. Structured evidence is in `releases/0.8.1-site-ux.json`; complete per-entry checksums accompany the separately delivered package manifest.

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

The same CI browser job passed its first seven suites, then exposed Composer handle replacement during a canvas redraw. The correction keeps selected handle nodes stable while updating geometry, preserving keyboard focus as well as the resize interaction. The corrected Composer recovery/focus and expanded controls suites pass locally. The new focus assertion fails against the original d81513 renderer at the expected image-load focus loss, demonstrating a meaningful regression. The subsequent final workflow verifies the corrected exact browser engine and full suite; this failed intermediate run is retained as evidence rather than hidden.

Visual screenshot review also corrected About page side spacing and hides an empty details column when no photo or statistics have been supplied. The updated desktop/tablet/mobile About/account/admin layout suite passes. That correction used asset build identity `0.8.1-20261019.2`; core VERSION remains 0.8.1.

## Intermediate correction run and Admin race

Run [39211611678](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678) tested commit [`1bb7f1903ac01252f22a5a564f18764998781678`](https://github.com/afterburn25/velvet_vixxen/commit/1bb7f1903ac01252f22a5a564f18764998781678):

| Exact job | Observed result |
| --- | --- |
| Repository/source contract, 117926053383 | PASS |
| PHP 8.2.34, 117926290960 | PASS: complete source and package regressions |
| PHP 8.3.35, 117926290989 | PASS: complete source and package regressions |
| MySQL 8.0.46, 117926291147 | PASS: 14 migrations / 82 tables, 32 installed-upgrade checks, 12 booking checks, merchandise transactions, reapply and complete rollback |
| HTTP/Chromium, 117926291045 | All 7 HTTP suites and first 10 browser suites PASS, including every Composer suite and 90 library renders; Site UX failed at immediate-refresh category persistence |
| Runtime package, 117927450368 | SKIPPED because the browser dependency failed |

The Composer node/focus correction is now verified on the actual CI Chromium 151 engine. The trigger-free booking rollback fixture passes real MySQL 8 without added database privileges. Browser evidence artifact [12168116914](https://github.com/afterburn25/velvet_vixxen/actions/runs/39211611678/artifacts/12168116914) contains the actual running-build captures; no gated runtime package was produced by this failed run.

That intermediate failure had a specific cause: native `details` toggle events are queued, so a refresh immediately after category activation can occur before the preference is saved. The correction observes native open-state changes at the microtask checkpoint, keeps explicit collapse preferences for the active category, and flushes pending changes on page exit. Native mouse and keyboard activation remain intact; initialization defaults are not recorded as explicit owner choices. The regression checks immediate saved state and reload, keyboard activation/focus, owner/site scope and malformed stored preferences without a timed delay. It demonstrably fails against the captured pre-fix Admin script at the missing preference before the next browser task. Current cache identity is `0.8.1-20261019.3`. The complete updated local Site UX suite passed, and the final all-green workflow above verifies the same correction on the actual CI engine.

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
| Installed HTTP regression | PASS, all seven suites in one stable aggregate (exit 0): members, commerce, add-ons, current platform, booking builder, merchandise and database updates. Log: final-run/http.log. The final feature GitHub CI above also passed. |
| Browser regression | PASS, all 12 real Chromium suites in one stable aggregate (exit 0), including the full 30-scene library/90 public renders, Composer controls, booking/merchandise and desktop/tablet/mobile layouts. Log: final-run/browser.log. Subsequent Composer focus and Admin timing corrections have meaningful regressions and pass the complete final CI run above. |

The long first library run printed full success, then its Bash wrapper exited after a concurrent harness edit. The wrapper now execs its disposable snapshot and deduplicates its suite list. The subsequent stable aggregate finished cleanly; that earlier outer exit is retained as a failed attempt.

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

## Completion boundary

The required combined gates, final committed-source package hashes, artifacts and repository evidence are complete. Intermediate failures remain above so the audit trail distinguishes what was fixed from what originally passed. Source, tests and these progress records are uploaded on the implementation branch; inspect PR #15 for its separate merge state.

Payments remain sandbox-only. Owner photographs/catalog content, production mail delivery and representative shared-hosting certification are not claimed. Filesystem ownership/write regressions are reproducible local/CI fixtures, not a test of the owner's actual hosting account.

# Testing — Velvet Vixxen 0.8.1

<!-- installed-build-followup:start -->
## Installed-update repair — Build 20261019.5; final CI pending

**The owner’s affected installation remains unresolved and has not been accessed.** The newest executable source is on `feature/0.8.1-site-ux-hero-library`, continuing from uploaded head `6b2130b6ec6f6d49bf95cab9f9bccaa4fad79f79`. Previous source/CI/download records below are historical and cannot establish that a customer deployment succeeded.

The exact previous saved downloads match their gated artifact. A retained-process negative control now reproduces a missed update failure: every replacement file matches on disk, while PHP OPcache with timestamp validation disabled still executes the earlier Composer and version file. Add Button is absent and Preview/Publish emit no requests. This is a proven laboratory failure mechanism; the owner’s server configuration is unknown.

**Implemented:** explicit build identity in the admin and package filenames; a complete 324-file shared-runtime hash inventory; and active-owner/CSRF-protected `admin/update.php` that diagnoses missing/mixed files and refreshes only this site’s verified PHP. The complete Upgrade contains 325 runtime files including that generated inventory. Missing/mixed contents block activation. Existing configuration, keys, owner records, database state, uploads and other applications’ PHP cache are preserved. The complete Upgrade is the primary update for an installed 0.8.1 site whose exact older build is unknown. The earlier eight-file installer repair is unchanged and is not a new Composer repair.

**Local acceptance passed on final runtime bytes:** all three actual historical-package upgrade cases on PHP 8.3.6 / Chromium 155.0.8059.84: root with persistent cache, nested with persistent cache (including `opcache.enable_cli=0`), and nested with cache genuinely disabled. The same PHP process and authenticated browser survive extraction and the real owner refresh. Both cached cases verify Add Button, preview staying open, persisted publication, the public Booking button, both image upload forms, exact uploaded bytes/browser decoding and zero uncaught JavaScript errors. Owner/member/demoted-owner, CSRF/method, mixed-file blocking, six desktop/mobile status layouts, private-state and unrelated-cache preservation checks passed. The new runtime feature suite passes 25 assertions. Existing source/HTTP and four packaged Composer action suites also passed locally; the broader exact-source PHP 8.2/8.3 and MySQL 8 gates remain mandatory in CI.

A separate four-case Apache/PHP-FPM audit of the prior delivered .4 runtime passed real route/save/publish/upload checks. Removing a physical API under ProxyPassMatch reproduces the exact `File not found.` response. No speculative routing change was added. See `docs/releases/0.8.1-hosted-update-audit.json` for that audit’s distinct scope.

Final new source commit, GitHub CI, gated artifact comparison and replacement download evidence are pending. See `docs/UPDATE_VERIFICATION.md`, `docs/releases/0.8.1-installed-update-acceptance.json` and `docs/releases/0.8.1-build5-package-audit.json`. Do not infer hosted resolution or final 0.8.1 completion from local acceptance. Issue #13 stays open, payments stay sandbox-only, and no 0.9.0 work is included.
<!-- installed-build-followup:end -->

Tests are mandatory release gates. Use PHP 8.2 or 8.3 with PDO MySQL, mbstring, fileinfo, OpenSSL, GD, Zip and sodium; Node for JavaScript syntax; Python with requests, BeautifulSoup and Playwright for HTTP/browser suites.

## Source and packaging

```bash
bash scripts/test.sh
python3 scripts/package.py --output artifacts/runtime
```

The source harness checks repository safety, copies clean runtime to a marked disposable directory, and runs source/installer/unit/feature/package regressions there. Never seed an installed demo in the working source tree or a real site. `scripts/clean_runtime.sh` is guarded and must not be used as a general site cleanup command.

## HTTP and browser

```bash
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
VELVET_BROWSER_TESTS=1 VELVET_TEST_PORT=8767 bash scripts/test_http.sh
```

The harness first execs its own disposable snapshot, starts and stops a real PHP server, and resets fixtures between suites. The aggregate runs 7 HTTP suites or 13 actual browser suites, including separate Composer buttons and 30 library scenes rendered at three viewport widths. `VELVET_TEST_SUITE` selects one exact test path. `VELVET_SCREENSHOT_DIR` stores actual browser evidence outside customer ZIPs. Set `VELVET_CHROMIUM` only when selecting an existing compatible browser. Browser interaction tests must run, not just compile.

## MySQL 8 migration gate

Provide `VELVET_TEST_DB_HOST`, `VELVET_TEST_DB_PORT`, `VELVET_TEST_DB_NAME`, `VELVET_TEST_DB_USER` and `VELVET_TEST_DB_PASSWORD` through environment configuration for a disposable test database, then run:

```bash
php tests/Database/migrations_mysql.php
```

The test rejects unsafe/non-empty databases, verifies a real prior 12→14 installed-site update with preserved configuration/keys/bookings/uploads, applies all migrations, checks 82 declared tables, repeats apply to verify idempotency, exercises merchandise/booking transactions, rolls back, and verifies no tables remain. Failed installed-site updates never invoke destructive down migrations; rollback in this test is limited to its disposable database. MariaDB results must be labelled MariaDB; they do not substitute for the MySQL 8 CI gate.

## Required final evidence

Record actual commands, versions, results, feature coverage and GitHub run URLs in `TEST_REPORT.md`. New user-facing 0.8.1 behavior has the explicit checklist in `../INCOMPLETE_WORK.md`. A configured but unexecuted CI job is pending. Customer ZIPs contain runtime only and start uninstalled; source, docs, workflows, SDK, tests, screenshots and all real site data stay outside them.

## Packaged installer ownership regression

`python3 tests/Installer/packaged_preflight.py` builds the actual fresh runtime ZIP, extracts it into a disposable fixture and runs PHP as an unprivileged user against separately owned static trees. The test requires Linux with root or passwordless sudo for that fixture; it must fail clearly if real separate ownership cannot be tested. The source matrix runs this gate on PHP 8.2 and 8.3. No production hosting paths or data are used.

## Timestamp-preserving installer repair

`tests/Installer/packaged_opcache.py` is a mandatory source-suite gate. It requires PHP OPcache and `unzip`, runs an isolated persistent localhost PHP process, demonstrates a stale same-timestamp negative control, and extracts the actual allowlisted runtime repair ZIP to verify corrected execution without a PHP restart or explicit cache invalidation. The test fails if OPcache/caching/timestamp preservation is ineffective. `scripts/package.py` keeps the repaired service component timestamp deterministic and later than the prior package; advance it for subsequent unfinished-installer service corrections. Fresh, upgrade and repair timestamps match for each shared dependency. The current database/layout repair includes six PHP files, one stylesheet and one JavaScript file; private/runtime/development exclusions remain mandatory.

## Existing-database installer and real browser continuation

Use an explicitly named disposable test database and the same `VELVET_TEST_DB_*` environment variables as the migration gate:

```bash
php tests/Installer/existing_database_mysql.php
python3 tests/Browser/installer_existing_database_playwright.py
```

Both suites build and extract the actual fresh customer ZIP into isolated marked directories. They reject a nonempty initial fixture database so fixture cleanup cannot target existing user tables. This test-harness safety check is separate from production installer behavior: the suites create partial/full/customized application databases and prove installation continues without deleting their contents.

The PHP suite covers empty/unrelated/core-only/full/ledgerless schemas, interrupted ALTER/default work, retained migration metadata, existing account authentication, row/hash/key/configuration/upload preservation, missing-key/schema/history conflicts, held filesystem/MySQL guards, and retries after interrupted finalization. CI runs it against real MySQL 8 on both PHP 8.2 and PHP 8.3, after the existing migration apply/verify/rollback gate has restored its disposable schema to empty.

The browser suite uses the real installer HTTP page at desktop/mobile widths and an isolated MySQL service. It covers actual AJAX connection/install, CSRF and 11-character rejection, acceptance of a 12-character owner password, wrong-owner refusal, post-final-config failure followed by ordinary HTTP-bootstrap retry, preserved records, cleanup and final owner login. Expanded technical details verify Server/Folders geometry and the missing redundant rule before/after Run Checks; password fields remain aligned and completion clears its spinner/scroll lock. PNGs/logs remain engineering evidence outside every customer ZIP.

The existing seven HTTP and all application browser suites remain mandatory. Do not substitute a copied HTML form, a simulated DB response, a same-owner filesystem, a stopped/restarted cache process or a passing skip for these release gates.

## Packaged public routes, uploads and Composer actions

```bash
python3 tests/Browser/packaged_media_upload_playwright.py
python3 tests/Http/public_routes_packaged.py
python3 tests/Browser/composer_actions_playwright.py
```

These suites extract the actual runtime ZIP into disposable sites and start PHP without any router script, at both a domain root and a nested installation path. No synthetic fallback may make a missing public entry point pass. The upload suite removes the physical upload entry point as a negative control, reproduces the host's literal `File not found.` response separately, and checks successful uploads, thumbnails, image decoding, retries, authentication and CSRF. The actions suite clicks the real Full Page Preview and Publish controls and follows real public button destinations at root/nested paths on desktop/mobile. It also clicks owner Posts View, default marketing links, banners and campaign destinations; verifies persistent banner dismissal; and requires exactly one new accepted campaign event with the correct installation path plus a real server POST/200 entry. Chromium can discard keepalive request/response instrumentation during navigation, so actual stored-event and server evidence determine beacon acceptance.

The source aggregate additionally runs the shared JSON response helper's Node regression, server-side button validation/persistence and public route/handler parity checks. The package gate requires all public compatibility files, checks the exact Media/Composer repair allowlist, and preserves configuration, storage, uploads and existing site state when the overlay is extracted. New changed components receive later deterministic ZIP timestamps and a new browser asset identity. Tests, screenshots, fixture data and logs remain outside every customer ZIP.

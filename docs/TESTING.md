# Testing — Velvet Vixxen 0.8.1

<!-- root-layout-repair:start -->
## Root-layout regression — Build 20261019.5 rejected; correction implemented, final gates pending

The owner rejected the delivered build because it restored the old route folders at the website root. Actual ZIP comparison confirms **25 extra root directories and 46 forwarding PHP files** (45 inside those directories plus `media.php`). The regression was introduced in `723205b7bddad06d4f603b539aea1a777bdd2ae5` and retained in Build 20261019.5. Earlier passing tests verified that implementation but failed to enforce the user's approved directory requirement. Do not present Build .5 as an acceptable organized-site release.

Restore the executable pre-regression layout: fresh ZIP directories are exactly `addons`, `admin`, `app`, `config`, `database`, `images`, `includes`, `install`, `public`, `storage`, `templates`, `uploads`, `vendor`; Upgrade excludes `config`, `install`, `storage`, `uploads`. Root files are exactly `.htaccess`, `VERSION`, `index.php`, `route.php`. Public implementations stay under `public/routes`, operational commands under `app/Console`. Agent-authored exceptions for root compatibility folders/files are superseded by the owner's explicit rejection.

The implemented correction uses the existing physical `route.php` dispatcher for generated public/API URLs, including root and subfolder installations without rewrite assistance. It must preserve query parameters, authentication, CSRF, entitlements, signed media and all real upload/button/Preview/Publish flows. An already-extracted update also requires a verified owner action to retire only exact known forwarding files and remove only empty retired directories; unknown/custom files, configuration, data, keys and uploads must remain intact.

**Build 20261019.6 is implemented; final exact-source CI and saved replacement downloads are pending.** Local PHP 8.3.6 source/JavaScript/security/ownership/package suites pass, including 692 safe-root-repair assertions, 87 retained repair-backup assertions, 136 installation-URL assertions and 836 central-route assertions. All seven HTTP suites pass. The actual checksum-pinned .5-to-.6 browser upgrade passes root/nested persistent-cache and disabled-cache cases, verifies exact 46-file/25-folder retirement, custom-item preservation, old-action redirection, reversible nonexecuting archives, and real upload/button/Preview/Publish actions. Actual packaged route sweeps pass all 87 aliases before/after cleanup; separate root/nested upload and four Composer-action cases pass. Local Apache/FPM checks verify canonical execution on six configurations, with the old pretty-path/private-denial limits explicitly scoped. The remaining application-browser gates and PHP 8.2/8.3/MySQL CI still govern final release verification. The independent root-directory allowlist gate now rejects the actual .5 Fresh/Upgrade maps. Require final execution of the real .5-to-.6 installed cleanup path, meaningful routing/browser regressions, PHP 8.2/8.3/MySQL 8 gates and matching saved artifact bytes before final delivery. The affected host remains unaccessed. Issue #13 stays open, PR #15 remains unmerged, payments stay sandbox-only and no 0.9.0 work is included.
<!-- root-layout-repair:end -->

<!-- installed-build-followup:start -->
## Installed-update repair — Build 20261019.5 verified and delivered

**The owner’s affected installation remains unresolved and has not been accessed.** Source, CI and download verification do not establish that the update is active on that host. The affected URL is still unavailable. Issue #13 remains open, PR #15 is unmerged, payments remain sandbox-only, and no 0.9.0 work is included.

The newest executable source is on `feature/0.8.1-site-ux-hero-library`: **`2bc178013c9c528f45a5d0447624f3cbaf4395f2`**, tree **`1b576e805d17b259a24e0d648e0568b54dc0bc37`**. The new runtime shows a distinct build ID, supplies a complete 324-file shared-runtime inventory, and adds active-owner/CSRF-protected **System → Update Status** / `admin/update.php`. It diagnoses missing/mixed files and refreshes only this site’s verified PHP. The previous upload, physical-route, Add Button, Preview and Publish implementations remain included. Existing configuration, keys, account records, database state and uploaded content are preserved.

**All seven jobs in [PR run 39315788377](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377) completed successfully.** Every actual checkout log identifies GitHub’s test merge `99396b2277d947a5f2cc47ef0e181d1c048891cd`. Its entire repository tree is identical to the source tree above, verified by both Git and the commit API; this is not a merged PR. The separate direct-push run remained pending at the 20:31:19 UTC audit and is not claimed as the completed release gate.

The final gates passed PHP **8.2.34/8.3.35** source/JavaScript/security/package suites; **MySQL 8.0.46** on both versions with 922 extracted-installer assertions per version, 32 installed-update checks, 12 booking transaction/snapshot checks and migration apply/verify/rollback; installer ownership/write and persistent-cache regressions; all seven HTTP and thirteen application-browser suites; actual packaged upload/routing/cleanup/Preview/Publish/button checks; and runtime-only archive exclusions/reproducibility. Browser execution used Playwright 1.62.0 / Chromium 151.0.7922.34, including 30 backgrounds, all full/thumbnail decodes, 90 public device renders, Booking Builder, About/admin/auth/navigation and merchandise/direct PPV.

The new mandatory historical-update test reconstructs the exact prior delivered ZIP from pinned `e5b04daa`, warms old PHP, and keeps the same browser and server through actual Upgrade extraction. Both cached root/subfolder cases reproduce matching files on disk while the old Composer still runs and Preview/Publish emit no requests. The real owner refresh then passes Add Button, retained preview, persisted publication, public Booking navigation, both image upload forms and exact image readback/decode. The disabled-cache control, owner/member/demoted-owner/CSRF/method boundaries, mixed-file refusal, six desktop/mobile status layouts and private-state/unrelated-cache preservation also pass. Local final-runtime acceptance independently passed on PHP 8.3.6 / Chromium 155.0.8059.84.

**The complete Upgrade is the primary download for an installed 0.8.1 site with an unknown earlier build.** `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261019.5-Upgrade-from-0.8.x.zip` contains 325 runtime entries, occupies 7,474,055 bytes and has SHA-256 `080e91477917171cf3bbc4438101507ac9bf9d597db61727797a82aa738b027d`. The full Fresh ZIP, 72-file focused repair and separate manifest were also updated. The eight-file installer-only repair is unchanged and was not redelivered as a new Composer fix.

[Runtime artifact 12219288424](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377/artifacts/12219288424) was downloaded and compared literally: all four ZIPs and the manifest match the frozen source-built files. The four new downloads were saved with their existing identities/version guards and retrieved again; every saved byte matches the gated artifact, verified **2026-10-19 20:30:12 UTC**. [Browser evidence 12219640705](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377/artifacts/12219640705) remains outside the customer ZIPs.

See `docs/UPDATE_VERIFICATION.md`, `docs/releases/0.8.1-installed-build-repair.json`, `docs/releases/0.8.1-installed-build-ci.json` and `docs/releases/0.8.1-installed-update-acceptance.json`. The separate Apache/FPM audit of the preceding .4 runtime and the earlier failed/intermediate test attempts remain recorded with their actual scopes. Preserve the unresolved hosted report until the owner’s served build and real actions are checked.
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

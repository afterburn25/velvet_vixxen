# Test Report — 0.6.0 Add-on Framework

## Local source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: **passed**

Verified:

- PHP syntax: 121 files
- public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 7 files
- smoke and one-page installer regression
- migration manifest: 9 migrations / 48 tables
- media, member, entitlement, commerce, add-on manifest, and add-on license unit tests
- Media Center, Members & Entitlements, Commerce, and Add-on Framework feature tests
- protected media, member security, signed webhook, add-on package, folder, and repository safety tests

Add-on coverage includes compatibility, permissions, dependencies/conflicts, checksums, unsigned acknowledgement, trusted/untrusted/invalid Ed25519 signatures, install/activate/deactivate, runtime registrations, settings, tasks, safe mode, boot-failure quarantine, update, backup, rollback, preserve-data uninstall, purge uninstall, migrations, logs, free/trial/commercial license states, domain binding, expiration, and signature rejection.

## Installed-demo HTTP acceptance

```bash
bash scripts/test_http.sh
```

Result: **passed**

Verified real HTTP forms and responses for member/account flows, subscriptions/PPV, billing controls, coupons, bundles, rentals, receipts, refunds/access revocation, owner Add-on Manager, active starter package, registered add-on admin page, homepage widget, public add-on route, setting persistence, task execution, and safe mode.

## Clean package verification

The release ZIP was extracted into a separate clean directory. The extracted copy started uninstalled, passed the complete non-database source suite, passed member/commerce/add-on HTTP acceptance, cleaned back to an uninstalled state, passed repository safety, and passed SHA-256 verification.

Release SHA-256: `8462746ee90ec0ec054e756e92afc63a9fc90e9f47f6bea39e7b0a6a156f065c`

## Browser status

Four Playwright suites exist for Media Center, Members & Entitlements, Commerce, and Add-on Framework. All pass Python syntax validation and are configured for CI.

**Local interactive Chromium is not claimed.** Navigation to the local server returned `net::ERR_BLOCKED_BY_ADMINISTRATOR` under a managed URL policy. The policy was not bypassed.

Release screenshots are static captures from HTML fetched from the running application and rendered with `page.set_content()`. They show actual page output but do not replace interactive acceptance.

## MySQL status

**Local live DDL execution is not claimed.** PDO MySQL and a MySQL/MariaDB server were unavailable.

The MySQL 8 CI test is configured to refuse an unsafe/non-empty database, apply all nine core migrations, verify 48 tables, apply and roll back an add-on-owned migration, roll back all core migrations, and require an empty database afterward.

## Security boundaries

- Add-ons execute PHP and are not claimed to be fully sandboxed.
- Invalid signatures and blocking package findings are rejected.
- Extension APIs enforce declared permissions.
- Private keys/environment files are blocked from distributable packages.
- Boot failures quarantine add-ons; safe mode bypasses optional code.
- Updates/uninstalls use backups and rollback paths.
- Repository safety rejects credentials, generated secrets, private signing keys, runtime state, customer data patterns, and mode `0777`.
- Payment remains sandbox-only.
- Online licensing, remote update feeds, customer downloads, and marketplace services are not complete in 0.6.0.

## Repository release gates still required

The complete executable source must be imported before GitHub can run PHP 8.2/8.3, MySQL 8, HTTP, task, repository-safety, and four Playwright jobs against this milestone. Issue #1 tracks source import; issue #7 tracks 0.6.0 delivery.

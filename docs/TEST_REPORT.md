# Test Report — 0.6.0 Add-on Framework

## Final local source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: **passed**

Verified:

- PHP syntax: 122 files
- public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 7 files
- smoke and one-page installer regression
- migration manifest: 9 migrations / 48 tables
- media, member, entitlement, commerce, add-on manifest, and add-on package unit tests
- Media Center, Members & Entitlements, Commerce, and Add-on Framework feature tests
- protected media, member security, signed webhook, add-on package, folder, and repository safety tests

Add-on coverage includes compatibility, permissions, dependencies/conflicts, checksums, unsigned acknowledgement, optional Ed25519 verification, install/activate/deactivate, runtime registrations, settings, tasks, safe mode, update/repair, backup, rollback, preserve-data uninstall, purge uninstall, migrations, activity logs, and failure isolation.

## Installed-demo HTTP acceptance

```bash
VELVET_TEST_PORT=8774 bash scripts/test_http.sh
```

Result: **passed**

Verified real HTTP forms and responses for member/account flows, subscriptions/PPV, billing controls, coupons, bundles, rentals, receipts, refunds/access revocation, owner Add-on Manager, active starter package, registered add-on admin page, homepage widget, public add-on route, setting persistence, deactivation/reactivation, and safe mode.

## Clean package verification

The final release ZIP was extracted into a separate clean directory. The extracted copy started uninstalled, contained no database credentials, generated keys, installation lock, runtime member/customer data, or installed add-on runtime state, passed the complete non-database source suite, passed member/commerce/add-on HTTP acceptance, cleaned back to an uninstalled state, passed repository safety, and passed SHA-256 verification.

Final release SHA-256: `862e9538a5b3c4efc8f8616fbbdc079b9fe252871252f5e2b275ab971394a01c`

## Browser status

Four Playwright suites exist for Media Center, Members & Entitlements, Commerce, and Add-on Framework. All pass Python syntax validation and are configured for CI.

**Local interactive Playwright is not claimed.** The packaging environment does not contain the required Playwright Chromium executable. GitHub CI installs Chromium before running the suites.

Release screenshots are static captures from HTML fetched from the running application. They show actual page output but do not replace interactive acceptance.

## MySQL status

**Local live DDL execution is not claimed.** PDO MySQL and a MySQL/MariaDB server were unavailable.

The MySQL 8 CI test is configured to refuse an unsafe/non-empty database, apply all nine core migrations, verify 48 tables, apply and roll back an add-on-owned migration, roll back all core migrations, and require an empty database afterward.

## Security boundaries

- Add-ons execute PHP and are not claimed to be fully sandboxed.
- Invalid signatures and blocking package findings are rejected.
- Extension APIs enforce declared permissions.
- Private keys and production environment files do not belong in distributable packages.
- Repeated boot failures can quarantine add-ons; safe mode bypasses optional code.
- Updates and uninstalls use backups and rollback paths.
- Repository safety rejects credentials, generated secrets, runtime state, customer data patterns, and mode `0777`.
- Payment remains sandbox-only.
- Online licensing, remote update feeds, customer downloads, and marketplace services are not complete in 0.6.0.

## Repository release gates still required

The complete executable source must be imported before GitHub can run PHP 8.2/8.3, MySQL 8, HTTP, task, repository-safety, and four Playwright jobs against this milestone. Issue #1 tracks source import; issue #7 tracks 0.6.0 delivery.

# Testing Policy and Commands

## Release gate

No release is complete until applicable source, installer, security, database, HTTP, browser, and add-on lifecycle tests pass. Documentation must distinguish checks actually run from checks merely configured for CI.

## Clean runtime

```bash
bash scripts/clean_runtime.sh
```

Run before source tests, packaging, or committing. It must remove installed configuration, secrets, sessions, logs, demo data, safe-mode locks, staged packages, backups, quarantine contents, and installed demo add-ons while preserving audited distributable packages.

## Source suite

```bash
bash scripts/test.sh
```

Covers PHP/JavaScript/Python syntax, smoke tests, installer regression, migration manifest, media/member/entitlement/commerce unit and feature tests, add-on manifest/license/lifecycle tests, protected-media/member/webhook/add-on-package security, organized folders, and repository safety.

## Installed-demo HTTP acceptance

```bash
bash scripts/test_http.sh
```

Covers member/account flows, subscription and PPV commerce, refunds/access revocation, owner Add-on Manager, starter package state, add-on admin page, homepage widget, public route, setting persistence, scheduled task execution, and safe mode. Run cleanup afterward.

## MySQL integration

Use only an empty disposable test database:

```bash
VELVET_TEST_DB_HOST=127.0.0.1 \
VELVET_TEST_DB_PORT=3306 \
VELVET_TEST_DB_NAME=velvet_vixxen_test \
VELVET_TEST_DB_USER=root \
VELVET_TEST_DB_PASSWORD=secret \
php tests/Database/migrations_mysql.php
```

The test applies nine core migrations, verifies 48 tables, applies/verifies/rolls back a sample add-on migration, rolls back core migrations, and requires the database to be empty afterward.

## Browser acceptance

```bash
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/media_center_playwright.py
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/members_entitlements_playwright.py
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/commerce_playwright.py
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/addons_playwright.py
```

The add-on suite verifies manager presentation, package/permission review, active starter add-on, registered admin page, homepage widget, public route, safe mode, and responsive behavior.

Static captures rendered with `page.set_content()` are useful visual evidence but do not count as interactive Playwright acceptance.

## Required add-on lifecycle coverage

- manifest/semantic-version validation
- core/PHP compatibility
- permissions, dependencies, and conflicts
- checksums and trusted/untrusted/invalid signatures
- blocked paths/content and unsigned acknowledgement
- install/activate/deactivate/reactivate
- update, backup, failed-update restoration, and rollback
- preserve-data and purge uninstall
- owned migration apply/rollback
- boot-failure quarantine and global safe mode
- free, trial, signed offline license, domain, expiration, and signature checks
- scheduled tasks and lifecycle log

## CI expectation

- PHP 8.2 and 8.3 source suites
- MySQL 8 migration/add-on-migration/rollback
- installed-demo member, commerce, and add-on HTTP acceptance
- four Chromium Playwright suites
- add-on task checks
- secret, private-signing-key, runtime-state, and unsafe-permission scans

## Evidence rule

Never state that MySQL, browser, live payment, email, remote licensing, update-server, or production-host testing passed without direct evidence. Record exact commands, results, failures, and environmental limits in `docs/TEST_REPORT.md`.

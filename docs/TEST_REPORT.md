# Test Report — 0.7.0 Messaging, Booking & Member Requests

## Source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: **passed**

Verified:

- PHP syntax: 149 source/test files
- public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 10 files
- smoke and one-page installer regression
- migration manifest: 11 migrations / 64 application tables
- media, members, entitlements, subscriptions/PPV, add-ons, licensing/updates, and communications unit/feature tests
- protected media, member security, signed webhooks, add-on security, organized folders, and repository safety

## Installed-demo HTTP acceptance

```bash
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
```

Result: **passed**

Verified real HTTP flows for members, subscriptions/PPV commerce, add-ons, add-on licensing/updates, private messaging, protected attachments, owner replies, saved replies, content requests, quotes/statuses, booking inquiries, booking administration, notifications, and communication controls.

## Runtime-only package verification

The 0.7.0 install ZIP contains only runtime files. It excludes GitHub/repository files, documentation, tests, SDK source, screenshots, reports, and development scripts. The ZIP contains 185 files, including 112 runtime PHP files and 11 database migrations.

A separate extraction passed:

- ZIP integrity
- SHA-256 verification
- runtime exclusion checks
- uninstalled/secret-free configuration checks
- 112 runtime PHP syntax checks
- no `0777` permissions
- full external source/security suite after overlaying the test harness
- all installed-demo HTTP acceptance suites
- clean-state repository safety after test cleanup

Release SHA-256:

```text
98247f13d7a36f77f65100f0e86ba3dfc2741e84e75b40b02a9b93a4e12c7b2b
```

## Browser status

Direct interactive Chromium navigation is blocked by a managed policy with `net::ERR_BLOCKED_BY_ADMINISTRATOR`. The policy was not bypassed. Browser suites remain configured for CI. Rendered screenshots document application output but do not replace interactive Playwright evidence.

## MySQL status

Local PDO MySQL and a MySQL/MariaDB server are unavailable. Live DDL execution is not claimed locally. The CI database test must apply all 11 migrations, verify 64 tables, roll back, and leave the disposable database empty.

## Result

**PASS — the 0.7.0 source and runtime-only installation package passed all available local non-database and HTTP acceptance gates.**

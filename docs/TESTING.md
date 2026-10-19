# Testing — Velvet Vixxen 0.8.1

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

The harness starts and stops a real PHP server in a disposable copy and resets fixtures between suites. `VELVET_TEST_SUITE` selects one exact test path. `VELVET_SCREENSHOT_DIR` stores actual browser evidence outside customer ZIPs. Set `VELVET_CHROMIUM` only when selecting an existing compatible browser. Browser interaction tests must run, not just compile.

## MySQL 8 migration gate

Provide `VELVET_TEST_DB_HOST`, `VELVET_TEST_DB_PORT`, `VELVET_TEST_DB_NAME`, `VELVET_TEST_DB_USER` and `VELVET_TEST_DB_PASSWORD` through environment configuration for a disposable test database, then run:

```bash
php tests/Database/migrations_mysql.php
```

The test rejects unsafe/non-empty databases, applies all migrations, checks actual declared tables, repeats apply to verify idempotency, rolls back, and verifies no tables remain. MariaDB results must be labelled MariaDB; they do not substitute for the MySQL 8 CI gate.

## Required final evidence

Record actual commands, versions, results, feature coverage and GitHub run URLs in `TEST_REPORT.md`. New user-facing 0.8.1 behavior has the explicit checklist in `../INCOMPLETE_WORK.md`. A configured but unexecuted CI job is pending. Customer ZIPs contain runtime only and start uninstalled; source, docs, workflows, SDK, tests, screenshots and all real site data stay outside them.

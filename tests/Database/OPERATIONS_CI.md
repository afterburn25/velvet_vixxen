# Isolated Operations restore gates

This is an additive workflow recommendation. Keep every existing required job,
matrix row and step, including the 82-step 0.8.14 baseline and later safeguard
gates. The main workflow remains owned by the release integration. Add this job to
the package job's `needs` list; do not substitute it for an existing database,
installer, accessibility or browser gate.

`operations_ci_fixture.php` creates a private **provisioning plan**, not a server.
It never accepts an administrative password, connects as root, resets a database
or drops a schema. The workflow imports that plan through its own ephemeral
service's local administrative client. Every job gets a new account and two new
database names. `CREATE` statements deliberately omit `IF NOT EXISTS`, so an
existing or partly provisioned pair cannot be adopted. A failed setup remains a
failed job; use a new job/service instead of deleting unknown data or weakening a
gate.

The account has privileges only on the exact source and restore schemas, without
global privileges, role grants or grant option. Database underscores are escaped
in `GRANT`, because they otherwise match additional database names in MySQL and
MariaDB ([MySQL reference](https://dev.mysql.com/doc/refman/8.0/en/grant.html),
[MariaDB reference](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant)).
Verification checks the actual engine and version, account, all grants, server
identity, both distinct database names and their empty state. MySQL 8.0 and
MariaDB 10.11 run separately; a missing UUID is never evidence of a different
server. A changed privilege configuration fails rather than broadening access.

Credentials and provisioning SQL stay under the runner's temporary directory in
owned 0700/0600 paths. The environment file is published only after verification.
Source it without shell tracing. Do not copy the fixture directory, SQL, client
log, environment or restore JSON into artifacts. The JSON returned by `verify`
contains only sanitized binding evidence; it has no passwords, grants containing
password hashes, server data-directory paths or installation records.

## Exact additive workflow example

The service is disposable and bound to loopback. Its example administrative
password belongs only to this ephemeral test container. It must never be reused
for an installation. MySQL/MariaDB client output during provisioning is kept in
the private fixture directory because a failed SQL statement can contain the
newly generated password.

```yaml
  operations-recovery:
    name: Operations restore (${{ matrix.engine }}, PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    timeout-minutes: 30
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3']
        engine: [mysql-8.0, mariadb-10.11]
        include:
          - engine: mysql-8.0
            image: mysql:8.0
            client: mysql
            health: mysqladmin ping --silent
          - engine: mariadb-10.11
            image: mariadb:10.11
            client: mariadb
            health: healthcheck.sh --connect --innodb_initialized
    services:
      database:
        image: ${{ matrix.image }}
        env:
          MYSQL_ROOT_PASSWORD: velvet_root_fixture
          MARIADB_ROOT_PASSWORD: velvet_root_fixture
        ports:
          - '127.0.0.1:3306:3306'
        options: >-
          --health-cmd="${{ matrix.health }}"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=12
    env:
      VVX_OPERATIONS_CI_DIR: ${{ runner.temp }}/vvx-operations-ci-${{ github.run_id }}-${{ github.run_attempt }}-${{ strategy.job-index }}
      VELVET_SCREENSHOT_DIR: ${{ github.workspace }}/artifacts/operations
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_mysql, fileinfo, mbstring, openssl, gd, zip, sodium, dom, posix
          coverage: none
      - uses: actions/setup-python@v5
        with:
          python-version: '3.12'
      - name: Install retained HTTP and browser dependencies
        run: |
          python -m pip install requests beautifulsoup4 playwright==1.62.0
          python -m playwright install --with-deps chromium
      - name: Check owned Operations fixture guards
        run: php tests/Feature/operations_ci_fixture.php
      - name: Prepare new private source and restore fixture
        env:
          VVX_ENGINE: ${{ matrix.engine }}
        run: |
          set -euo pipefail
          umask 077
          php tests/Database/operations_ci_fixture.php prepare "$VVX_OPERATIONS_CI_DIR" "$VVX_ENGINE" 3306
      - name: Provision only this disposable service's new fixture pair
        env:
          VVX_SERVICE: ${{ job.services.database.id }}
          VVX_IMAGE: ${{ matrix.image }}
          VVX_CLIENT: ${{ matrix.client }}
          MYSQL_PWD: velvet_root_fixture
        run: |
          set -euo pipefail
          umask 077
          test -n "$VVX_SERVICE"
          test "$(docker inspect --format '{{.Config.Image}}' "$VVX_SERVICE")" = "$VVX_IMAGE"
          test "$(docker inspect --format '{{.State.Health.Status}}' "$VVX_SERVICE")" = healthy
          if ! docker exec -i -e MYSQL_PWD "$VVX_SERVICE" "$VVX_CLIENT" --no-defaults -uroot --batch \
              < "$VVX_OPERATIONS_CI_DIR/provision.sql" \
              > "$VVX_OPERATIONS_CI_DIR/provision-client.log" 2>&1; then
            echo 'Disposable Operations provisioning failed; private SQL and client output were preserved outside artifacts.' >&2
            exit 1
          fi
      - name: Verify actual engine and exact empty fixture pair
        run: |
          set -euo pipefail
          mkdir -p "$VELVET_SCREENSHOT_DIR"
          php tests/Database/operations_ci_fixture.php verify "$VVX_OPERATIONS_CI_DIR" \
            > "$VELVET_SCREENSHOT_DIR/database-provisioning.json"
      - name: Actual SQL encrypted backup and isolated restore
        run: |
          set -euo pipefail
          source "$VVX_OPERATIONS_CI_DIR/source.env"
          php tests/Database/operations_mysql.php
          php tests/Database/operations_ci_fixture.php verify "$VVX_OPERATIONS_CI_DIR" \
            > "$VELVET_SCREENSHOT_DIR/database-after-source.json"
      - name: Actual packaged Operations HTTP acceptance
        run: |
          set -euo pipefail
          source "$VVX_OPERATIONS_CI_DIR/source.env"
          python tests/Http/operations_http.py --mode both
          php tests/Database/operations_ci_fixture.php verify "$VVX_OPERATIONS_CI_DIR" \
            > "$VELVET_SCREENSHOT_DIR/database-after-http.json"
      - name: Actual packaged Operations desktop and mobile acceptance
        run: |
          set -euo pipefail
          source "$VVX_OPERATIONS_CI_DIR/source.env"
          python tests/Browser/operations_playwright.py --mode both
          php tests/Database/operations_ci_fixture.php verify "$VVX_OPERATIONS_CI_DIR" \
            > "$VELVET_SCREENSHOT_DIR/database-after-browser.json"
      - name: Upload actual Operations evidence
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: operations-${{ matrix.engine }}-php${{ matrix.php }}-evidence
          path: artifacts/operations/
          if-no-files-found: error
```

The runner destroys this service at the end of the job. There is intentionally no
root-powered cleanup command or reusable database reset in the provisioning
helper. The source/HTTP/browser suites already prove that they own an initially
empty pair and remove only their fixture tables. Each subsequent `verify` fails
if a suite leaves anything behind. Never hide a failed cleanup by dropping the
whole database from a workflow `always()` handler.

The HTTP and Playwright helpers bind their actual listener to `127.0.0.1`, test
root and nested installations, use real CLI workers and read the independently
restored database and files. `--mode both` includes file storage and the explicit
SQL engine; the reports' `mysql` storage label describes the PDO backend, while
their actual server fields establish MySQL versus MariaDB. Preserve those fields
when auditing evidence. The browser report also records the freshly built runtime
identity, screenshots, viewport geometry and unexpected JavaScript/SMTP activity.

The source suite can additionally include
`php tests/Feature/operations_ci_fixture.php` with the other source gates. This
fixture gate does not replace `tests/Feature/operations.php`, the SQL restore gate,
the actual package/browser tests or manual review of their screenshots.

## Evidence limits

A helper guard pass is not a SQL restore pass. Local owned-server verification is
not GitHub CI, a production backup or a customer-host recovery. A final release
must bind the actual required job logs, database engine records, test source
hashes, runtime package hashes and downloaded artifacts to the release source.
The runtime's documented host configuration, external key, request-drain and
quarantine requirements remain unchanged.

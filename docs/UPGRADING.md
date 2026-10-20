# Updating an installed Velvet Vixxen site

Use the complete **0.8.6 / Build 20261020.7 Upgrade-from-0.8.x** package for an existing 0.8.x installation. Follow [Update Verification](UPDATE_VERIFICATION.md) to verify uploaded files and refresh running PHP. **System → Database Updates** and the equivalent CLI apply any pending database migrations without rerunning the installer.

## Prepare a verified backup

Before uploading or applying an update:

1. Create a fresh database backup through the hosting control panel or your database administration tool.
2. Back up the active `config/app.php`, `storage/install.lock`, customer uploads, and other live application data. Keep the backup outside the directory being updated. A repair archive containing configuration files is not a complete database backup.
3. Check that the backup job finished successfully and contains the expected tables and files. Confirm the restore procedure. Record the backup timestamp and hosting backup ID or backup location.
4. Arrange a quiet maintenance window. Schema changes can commit immediately, and the updater does not place the entire public site into maintenance mode.

The updater requires an operator acknowledgment and a backup reference before applying pending SQL. It records that acknowledgment; it does not inspect or certify the contents of your hosting backup.

## Upload and apply through the owner dashboard

1. Upload the upgrade package into the existing site directory. The upgrade package omits the live configuration, installation lock, storage, customer uploads, installed add-ons, and installer. Preserve those existing directories and files.
2. Sign in as the owner. Open **System → Database Updates**.
3. Check the displayed database name, server version, and pending migrations. The updater verifies that the active configuration, installation lock, and database contain the same installation ID and that the existing security keys are present. Applied migration files must retain their recorded checksums.
4. Enter the verified backup reference, acknowledge that you can restore the backup, and select **Apply Pending Updates**.
5. Review the completed migration list and refresh the page. The pending list should be empty. Check the public booking form, a saved booking inquiry, the merchandise catalog, and existing account/order data as appropriate for your installation.

For a database that already has the first 12 baseline migrations, this 0.8.1 continuation adds:

| Migration | Purpose |
| --- | --- |
| `202610190013_booking_form_builder` | Stores each booking submission's original questions, choices, and answers separately from later form edits. |
| `202610190014_merchandise` | Adds merchandise products, variants, inventory, and order reservations. |

Version 0.8.6 additionally includes `202610200015_marketing_sql_storage`, which creates two marketing storage tables. The current complete package contains 15 migrations. An installation's actual pending list depends on its recorded history. A database that is already current remains unchanged when the updater is run again.

Applying migration 015 does not import marketing data or change the active backend. After the database update, review **System → Marketing Storage** for the separate explicit owner move described in [Marketing Storage](MARKETING_STORAGE.md).

**File-storage mode:** SQL migrations do not apply. The dashboard explains that no SQL update is needed, and the command-line apply operation safely reports a no-op.

## Apply from the command line

Run these commands from the installed site's root directory, using a PHP version supported by the package. The updater requires PDO MySQL for SQL-backed sites.

Inspect the current plan without applying SQL:

```bash
php app/Console/migrate.php --status
```

The JSON result lists the database, server version, applied migrations, pending migrations, the plan hash, and the previous update result. This command does not invoke the public application bootstrap or installer recovery.

After verifying the backup, apply the pending plan:

```bash
php app/Console/migrate.php --apply --backup-confirmed \
  --backup-reference='Hosting backup ID and verified timestamp'
```

To require the exact plan you inspected, also pass the `plan` value from the status result:

```bash
php app/Console/migrate.php --apply --backup-confirmed \
  --backup-reference='Hosting backup ID and verified timestamp' \
  --plan='HASH_FROM_THE_STATUS_RESULT'
```

The command exits nonzero on failure. Run `php app/Console/migrate.php --help` for the supported options. Access to this command is through the server's command line; the web route to the console script is not public.

## If an update is blocked or interrupted

A mismatched installation ID, changed checksum, unsafe linked path, missing migration registry, unavailable database, or unwritable private status directory blocks the update. Keep the active configuration and database intact and resolve the reported issue. Do not reinstall or generate new keys to bypass a failed verification.

Only one updater can run at a time. The service holds a private filesystem lock and a database advisory lock. Its private status record is `storage/private/database-updates/last-run.json`; the owner dashboard displays the useful result without exposing that file publicly.

**There is no automatic rollback on an installed site.** MySQL and MariaDB schema operations can commit before a migration completes. If a migration fails:

- Completed migrations remain recorded and are skipped on the next run.
- A partially completed migration may have changed the schema without being recorded as completed.
- The updater preserves that partial state and reports the last migration reached. It does not execute migration `down` statements against the existing installation.
- Inspect the failed SQL and the resulting database state. Decide whether to complete a reviewed correction or restore the verified backup. Avoid blindly retrying a non-idempotent statement.

After reviewing a failed or interrupted run, the dashboard requires an explicit acknowledgment of that review. The CLI equivalent is:

```bash
php app/Console/migrate.php --apply --backup-confirmed \
  --backup-reference='Verified backup after reviewing the partial update' \
  --reviewed-failure
```

An existing booking inquiry remains readable before migration 013 is applied. New submissions through the configurable builder are blocked with a pending-migration message until its snapshot table is available; the site does not reopen the installer or silently discard the submitted answers.

## Historical 0.8.1 updater verification

The local integration tests used **MariaDB 10.11.14** with PDO MySQL. They simulated the actual 12 → 14 migration upgrade and verified preservation of an existing booking, customer data, configuration bytes, installation-lock bytes, security keys, and customer uploads. They also exercised failed DDL without destructive rollback, completed-migration retention, reviewed retries, both concurrency locks, permission/path failures, and the real command-line updater. The test database was completely rolled back after the suite. GitHub's separate MySQL 8 CI job provides its own engine-specific result; local MariaDB evidence is not a claim that MySQL 8 was run locally.

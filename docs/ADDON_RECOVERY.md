# Add-on updates and recovery

The Add-on Manager keeps package files, the installed version and the database migration ledger aligned. MySQL schema changes commit independently of ordinary application transactions, so recovery uses recorded progress and keeps an unresolved add-on disabled.

## Before an update or rollback

Use a package from a source you trust and review its requested permissions. Add-ons execute PHP; the framework does not fully sandbox that code.

Keep a verified database backup before an update that changes schema, and before choosing Rollback or Uninstall with **Purge data**. The built-in package backup preserves add-on files. A schema rollback can remove tables or columns, and a purge can remove add-on data. Reinstalling the old schema cannot recover those deleted rows or values.

The ordinary install/update flow still reviews compatibility, dependencies, conflicts, checksums and signatures. An add-on package containing migrations must request `database.migrate` in its manifest. The whole migration plan is checked before its first migration SQL statement. It cannot change core tables, adopt an unrelated existing table or reference tables it does not own.

## What happens when a change fails

If the application knows which new schema statements completed, it can reverse the objects those statements added. It preserves the prior package until the replacement files and installed-version record are committed. A failure during this process can leave an item under **Add-on recovery** on the Add-on Manager page.

An unresolved add-on cannot be activated or booted. Its ordinary update, rollback and uninstall controls are held until the recorded state is resolved. Other add-ons and unrelated site data remain outside this recovery operation.

When the page offers **Retry recorded recovery**, the recorded failure is definite. Submitting that action verifies the retained package files, registry identity and exact reviewed migration definitions before it continues. Resolve the reported database or package problem, then use that button while signed in as the current active owner. A retry continues the recorded operation from its saved position; it does not reset migration history or blindly rerun completed SQL. A still-failing step stays held and keeps its progress.

The page explains whether the retry restores the previous package, completes an explicitly selected rollback or completes an uninstall. A completed rollback or purge can still require your database backup to restore deleted data.

## Interrupted or uncertain outcomes

A stopped process or lost database connection can leave it uncertain whether a particular SQL statement committed. These cases remain disabled for database and package review, without an automatic retry button. Preserve the private recovery records and the retained package files. Reconcile the database and package state against a verified backup before clearing any hold; deleting the journal by itself does not establish that the installation is consistent.

The owner page contains a safe status summary. SQL statements and private recovery paths are not included in the recovery status panel or its retry result. Recovery submission requires a valid session token and a current active-owner account.

## Migration authoring

Keep previously applied migration IDs and their original file bytes. The stored checksum must match; modifying an already applied migration is rejected. Retain the migrations that establish table ownership when adding later migrations.

New automatically recoverable migrations support their own InnoDB tables and additive named columns, indexes and foreign keys on tables owned by the same add-on. Each migration needs explicit `up` and `down` lists, and the `down` plan must reverse its own additions. A table must explicitly use `ENGINE=InnoDB`. Changes that rewrite retained rows or narrow existing columns require separately reviewed database work and a backup; this automatic update path rejects them.

For example, an initial owned table can use:

```json
{
  "id": "001_notes",
  "up": [
    "CREATE TABLE vendor_example_notes (id INT PRIMARY KEY, note_text VARCHAR(255) NOT NULL) ENGINE=InnoDB"
  ],
  "down": [
    "DROP TABLE IF EXISTS vendor_example_notes"
  ]
}
```

An explicit package rollback removes the migrations absent from the selected older package before activating its files. A preserve-data uninstall retains the add-on's data and migration history; a purge applies the reviewed down plan and removes the related registry, settings and task metadata consistently.

## Acceptance scope

The release's real-MySQL gate extracts the actual Fresh ZIP and exercises initial installation, repair, update, selected rollback, preserve-data and purge uninstall, ownership/checksum rejection, partial failures and process interruption. Separate HTTP and desktop/mobile browser cases use the real owner recovery form. Exact executed versions, assertion counts, source identities and CI results belong in the numbered release record.

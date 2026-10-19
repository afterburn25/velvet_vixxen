# 0.8.1 Installer Database and Layout Repair

Use this repair for an already-uploaded **unfinished Velvet Vixxen 0.8.1 installation**, including the error that lists existing `migrations`, `admins`, `settings`, `media_assets`, `scenes`, `scene_versions`, `canva_templates` and `audit_log` tables. The earlier folder-only repair does not contain the database continuation.

## Continue the installation on the server

1. Download `Nikki-Wilder-Velvet-Vixxen-0.8.1-Installer-Database-Repair.zip`.
2. Open the existing website folder in the hosting file manager: the folder containing `app`, `install`, `includes` and `public`.
3. Upload and extract the repair ZIP into that folder. Allow the matching application files to be replaced; extraction also adds the new schema-reconciliation helper.
4. Refresh the existing installer page. Its CSS and JavaScript use an updated cache identifier. Click **Run Checks**, keep the same database details, and continue installation.
5. If an owner account was already created, enter that existing owner's email and password. If no account exists yet, the form creates it. Passwords still require at least 12 characters.
6. After verified completion, use **Open Owner Login**.

The database can already contain unrelated tables, a previous partial attempt or a compatible complete Velvet Vixxen schema. The installer keeps existing data and completes missing work. A failed attempt remains resumable on a later browser request.

## Files in this repair

| Runtime file | Purpose |
| --- | --- |
| `app/Core/MigrationRunner.php` | Continue migration work without destructive automatic rollback. |
| `app/Core/MigrationResumeSchema.php` | Inspect known schema definitions and apply only missing compatible changes. |
| `includes/installation_recovery.php` | Preserve pending setup and serialize automatic recovery/installer retirement. |
| `install/services/InstallerService.php` | Reuse the database, authenticate/preserve its owner, keep keys/defaults/identity and verify final activation. |
| `install/services/FileSystemService.php` | Include the earlier packaged-folder/ownership correction. |
| `install/views/single-page.php` | Explain existing-database support and load the revised installer assets. |
| `public/assets/css/installer.css` | Remove the extra Folders rule/inset and align Server/Folders and password fields. |
| `public/assets/js/installer.js` | Clear the loading spinner and scroll lock after successful installation. |

The ZIP contains exactly those eight runtime files. Configuration, storage, uploads, customer records, migration source files, repository documents, tests, workflows, screenshots and secrets are outside the repair. Extract it over the existing site folder; do not delete the database or replace its configuration to address this error.

## Preservation and completion behavior

Existing owner hashes, settings, scenes and saved records remain intact. Missing tables/compatible schema changes are added, and an absent migration ledger can be reconstructed after inspection. Recorded migration checksums and known schema definitions are checked before application writes. Existing content defaults are not republished or reset.

Complete staged configuration keeps the original installation identity and key set. Matching preserved configuration backups can also supply those keys. Existing encrypted or HMAC-protected license records require their original keys; if those files are missing, the installer reports the specific missing configuration rather than generating replacement keys over that data. A mismatched owner or incompatible table receives a precise error with the existing records preserved.

The pending marker remains in place through final database/configuration/lock verification. Refreshing after a paused attempt cannot make an older completed backup override the pending configuration. Concurrent setup/recovery/retirement requests cannot move or overwrite an active attempt. A normally completed installation stays locked and uses the existing installed-site update flow.

## New installations and installed-site updates

The rebuilt `Nikki-Wilder-Velvet-Vixxen-0.8.1-Fresh-Install.zip` contains the same corrections and starts uninstalled. Its user-facing download may be named `Nikki-Wilder-Velvet-Vixxen-0.8.1-Site-UX-Fresh-Install.zip`; the bytes are identical.

The rebuilt `Nikki-Wilder-Velvet-Vixxen-0.8.1-Upgrade-from-0.8.x.zip` preserves the normal exclusion of `config`, `storage`, `uploads` and `install`. It includes applicable Core/recovery/public asset corrections. Use this small repair ZIP for the unfinished installer reported here; use [UPGRADING.md](../UPGRADING.md) for an already installed website.

## Evidence

Current executed tests, exact source/CI provenance and package checksums are in [TEST_REPORT.md](TEST_REPORT.md), [RELEASE_MANIFEST.txt](../RELEASE_MANIFEST.txt) and [the database-repair release record](releases/0.8.1-installer-database-repair.json). Required gates include actual packaged installation on MySQL 8 with PHP 8.2/8.3, real desktop/mobile browser continuation and layout checks, source/JavaScript/security, foreign-owner write tests, persistent OPcache repair, and runtime-only exclusion checks. Previous feature HTTP/browser suites remain mandatory. This guide describes the delivered application behavior; it does not claim the repair was applied to the owner's host.

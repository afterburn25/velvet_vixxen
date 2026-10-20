# 0.8.1 Installer Database and Layout Repair

Use this repair for an already-uploaded **unfinished Velvet Vixxen 0.8.1 installation**, including the error that lists existing `migrations`, `admins`, `settings`, `media_assets`, `scenes`, `scene_versions`, `canva_templates` and `audit_log` tables. The earlier folder-only repair does not contain the database continuation.

The current **Build 20261020.2** overlay also includes the installer sender-validation correction and contains **11 files**. Its `repair_build_id` identifies this repair revision; it does not change an installed site's runtime inventory/build or install the owner Email & SMTP editor. The release is merged through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17), all seven [required CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39361581062) passed, and the saved repair was retrieved and compared byte for byte with the gated artifact at **2026-10-20 03:11:47 UTC**. See [the current release record](releases/0.8.1-owner-mail-settings.md) and [delivery evidence](releases/0.8.1-owner-mail-settings-delivery.json).

## Continue the installation on the server

1. Download the verified `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261020.2-Installer-Database-Repair.zip`.
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
| `install/services/InstallerService.php` | Reuse the database, authenticate/preserve its owner, keep keys/defaults/identity, validate sender settings and verify final activation. |
| `install/services/FileSystemService.php` | Include the earlier packaged-folder/ownership correction. |
| `install/views/single-page.php` | Explain existing-database support and load the revised installer assets. |
| `public/assets/css/installer.css` | Remove the extra Folders rule/inset and align Server/Folders and password fields. |
| `public/assets/js/installer.js` | Clear the loading spinner and scroll lock after successful installation. |
| `install/index.php` | Validate the test-mail request and sender fallback before SMTP. |
| `install/services/EmailAddress.php` | Validate and normalize safe pasted addresses without depending on a new installed runtime class. |
| `install/services/SmtpTester.php` | Use the validated sender and safe SMTP connection settings. |

The ZIP contains exactly those 11 runtime files: nine PHP files, one stylesheet and one JavaScript file. The four changed sender files are `install/index.php`, `InstallerService.php`, `EmailAddress.php` and `SmtpTester.php`; `InstallerService.php` was already part of the original eight-file overlay. Configuration, storage, uploads, customer records, migration source files, repository documents, tests, workflows, screenshots and secrets are outside the repair. Extract it over the unfinished site's existing folder; do not delete the database or replace its configuration to address this error. The [original eight-file evidence](releases/0.8.1-installer-database-repair.json) remains historical.

## Preservation and completion behavior

Existing owner hashes, settings, scenes and saved records remain intact. Missing tables/compatible schema changes are added, and an absent migration ledger can be reconstructed after inspection. Recorded migration checksums and known schema definitions are checked before application writes. Existing content defaults are not republished or reset.

Complete staged configuration keeps the original installation identity and key set. Matching preserved configuration backups can also supply those keys. Existing encrypted or HMAC-protected license records require their original keys; if those files are missing, the installer reports the specific missing configuration rather than generating replacement keys over that data. A mismatched owner or incompatible table receives a precise error with the existing records preserved.

The pending marker remains in place through final database/configuration/lock verification. Refreshing after a paused attempt cannot make an older completed backup override the pending configuration. Concurrent setup/recovery/retirement requests cannot move or overwrite an active attempt. A normally completed installation stays locked and uses the existing installed-site update flow.

## New installations and installed-site updates

The current `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261020.2-Fresh-Install.zip` contains the same corrections and starts uninstalled. Never run Fresh setup over an installed website.

The current `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261020.2-Upgrade-from-0.8.x.zip` preserves the normal exclusion of `config`, `storage`, `uploads` and `install`. It includes the complete installed runtime and owner settings dependencies. Use this small repair ZIP for unfinished setup; use [Update Verification](UPDATE_VERIFICATION.md) and [Upgrading](UPGRADING.md) for an already installed website.

## Evidence

Current executed tests, source/CI status and package checksums are in [TEST_REPORT.md](TEST_REPORT.md), [RELEASE_MANIFEST.txt](../RELEASE_MANIFEST.txt) and [the Build 20261020.2 release record](releases/0.8.1-owner-mail-settings.md). Required gates passed, including actual packaged installation on MySQL 8 with PHP 8.2/8.3, real desktop/mobile browser continuation and layout checks, source/JavaScript/security, foreign-owner write tests, persistent OPcache repair, and runtime-only exclusion checks. Previous feature HTTP/browser suites remain mandatory. The original database/layout release evidence is retained as history. The current repair is verified and delivered; application to the affected owner's host and its behavior there remain unverified.

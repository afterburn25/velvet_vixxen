# 0.8.1 Installer Database and Layout Repair

Use this repair for an already-uploaded **unfinished Velvet Vixxen 0.8.1 installation**, including the error that lists existing `migrations`, `admins`, `settings`, `media_assets`, `scenes`, `scene_versions`, `canva_templates` and `audit_log` tables. The earlier folder-only repair does not contain the database continuation.

The unchanged **0.8.1 / repair Build 20261020.3** overlay contains **11 files**, including the database/folder and strict sender corrections. Its `repair_build_id` identifies the unfinished-installer repair; it does not advance an installed site's runtime build or install the owner Email & SMTP editor. Its delivered bytes and saved identity remain unchanged. Consult [the current release evidence](TEST_REPORT.md) for exact artifact and delivery comparisons. The [original sender-release record](releases/0.8.1-owner-mail-settings.md) and [its delivery evidence](releases/0.8.1-owner-mail-settings-delivery.json) describe the historical Build .2 release.

## Continue the installation on the server

1. Download the verified `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261020.3-Installer-Database-Repair.zip`.
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

The current `Nikki-Wilder-Velvet-Vixxen-0.8.6-Build-20261020.7-Fresh-Install.zip` includes the corrections and starts uninstalled. Use it for a new installation; use the complete Upgrade for an existing installed site.

The current `Nikki-Wilder-Velvet-Vixxen-0.8.6-Build-20261020.7-Upgrade-from-0.8.x.zip` preserves the exclusion of `config`, `storage`, `uploads` and `install` and supplies the complete installed runtime. The small installer repair remains for unfinished 0.8.1 setup. Follow [Update Verification](UPDATE_VERIFICATION.md) and [Upgrading](UPGRADING.md) for installed websites.

## Evidence

Current executed tests, source/CI status and package identities are in [TEST_REPORT.md](TEST_REPORT.md), [RELEASE_MANIFEST.txt](../RELEASE_MANIFEST.txt) and the current 0.8.6 release record linked there. Preserve required source, real-MySQL, installer browser, ownership, persistent-OPcache, HTTP and runtime-package gates. Earlier database/layout and Build .2 sender-release records remain historical. The unchanged 0.8.1 / repair Build 20261020.3 download keeps its own identity; the affected customer host remains unverified.

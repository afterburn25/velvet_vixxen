# 0.8.1 Installer Folder Repair

This correction applies to an already-uploaded **unfinished 0.8.1 installation** that reports `Directory could not be created` for unused image/font/icon/template folders. It is not an application upgrade or a new installer.

## Resume the installation already on the server

1. Download `Nikki-Wilder-Velvet-Vixxen-0.8.1-Installer-Folder-Repair.zip`.
2. In the hosting file manager, open the existing website folder containing `install`, `app`, `images` and `public`.
3. Upload and extract the repair ZIP there, allowing its one existing file to be replaced: `install/services/FileSystemService.php`.
4. Refresh the same browser installer page and click **Run Checks**. Continue setup with the same database details.

The ZIP contains only the corrected filesystem service. It does not replace configuration, storage, uploads, database files, other installer files, or artwork. No database reset, permission widening, or manual creation of empty folders is needed for this defect. A site already installed successfully does not need this installer-only repair.

## New installation

The current full `Nikki-Wilder-Velvet-Vixxen-0.8.1-Fresh-Install.zip` includes the same correction. Use it for a new installation. Existing installed sites continue to use the ordinary state-preserving upgrade workflow in `UPGRADING.md`.

## Cause and correction

The prior directory manifest incorrectly made nine unused empty asset/template folders mandatory. The runtime-only ZIP correctly omitted those empty folders. When a hosting panel owns the extracted static trees, PHP cannot create the unused children, so readiness failed. Unused `database/seeds` was the same manifest defect and is removed too.

The corrected installer checks actual packaged application/assets/templates for presence and readability. It does not create or chmod those read-only trees. Real runtime write/rename/read/delete probes and the existing limited ownership repair remain for configuration, storage, uploads, add-ons and database runtime directories. A missing actual application folder still blocks readiness with an extraction error; the installer never substitutes an empty directory for missing source.

## Evidence

The new `tests/Installer/packaged_preflight.py` builds and extracts the real runtime ZIP and executes PHP as a different unprivileged user from the static-file owner. It verifies the exact earlier nine-path failure, corrected initial/repeated probes, preserved static bytes/modes/ownership, and continued blocking of genuine write/extraction failures. Detailed observed results and CI/package hashes are in `TEST_REPORT.md` and `../RELEASE_MANIFEST.txt`.

The repair ZIP remains runtime-only; this guide and the regression code are kept in GitHub.

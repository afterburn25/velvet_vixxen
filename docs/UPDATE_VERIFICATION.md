# Restore the organized Velvet Vixxen installation

Build **20261019.5** was rejected because it reintroduced 25 public route folders at the website root. Build **20261019.6** restores the approved layout and supplies a safe repair for a site that already extracted that package. The correction is still in verification until the final release evidence is recorded in `docs/TEST_REPORT.md`.

## Existing 0.8.1 installation

1. Extract the contents of the complete **Build-20261019.6 Upgrade** ZIP into the existing active website folder. Replace matching application files. The archive has no enclosing product folder.
2. Sign in with the existing owner account and open **`admin/repair.php`** under this installation's URL. This new entry is necessary when the previous update page or PHP classes are still cached. The updated System → Update Status link also opens this page.
3. Check the file-verification result. If any application files are missing or different, complete the extraction and reload. Click **Refresh application PHP** and confirm the running build becomes **20261019.6**.
4. Click **Restore organized folders**. The repair retires only the exact 46 known forwarding files from the rejected package and removes their 25 directories only when empty. The page reports what was removed and lists any custom or modified items left in place.
5. Open Visual Composer and verify image upload, Add Button, Full Page Preview and Publish.

The Upgrade excludes `config`, `storage`, `uploads` and `install`. It does not reinstall the site, create a new owner, replace keys, run SQL or replace saved content. A ZIP overlay alone cannot remove folders from an earlier extraction, which is why the verified owner repair action is required.

The repair preserves the exact old forwarding bytes as base64 JSON under protected private repair storage. It does not leave executable PHP backups. Unknown or changed files, symbolic links and custom subfolders are preserved in place. A folder containing one will remain and be listed. Failed filesystem operations are reported and can be retried; no broad recursive cleanup or world-writable permissions are used.

## Approved package layout

| Variant | Allowed root directories | Root files |
| --- | --- | --- |
| Fresh installation | `addons`, `admin`, `app`, `config`, `database`, `images`, `includes`, `install`, `public`, `storage`, `templates`, `uploads`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |
| Existing-site Upgrade | `addons`, `admin`, `app`, `database`, `images`, `includes`, `public`, `templates`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |

Public pages and APIs are implemented under `public/routes`. Generated internal URLs use the real `route.php` dispatcher, so actions can execute on root or subfolder installations without relying on rewrites for missing PHP files. Existing pretty paths remain aliases through the real `.htaccess` rules. Old external pretty links need working web-server rewrite handling; the package does not pretend a removed PHP file is still physically present.

## Evidence and limits

The new installed-update test reconstructs the exact rejected Build .5 ZIP from its pinned source/hash, keeps the same browser and PHP process through extraction, proves that extraction alone leaves the unwanted folders, refreshes through the new owner entry and performs real cleanup and application actions. Separate package tests reject the actual .5 root maps and check the exact approved root names.

Source/package tests, local acceptance, CI and saved download hashes are recorded separately in `docs/TEST_REPORT.md` and the release evidence. The affected customer host has not been accessed; its actual served build and behavior remain unverified. Payments remain sandbox-only and no 0.9.0 work is included.

# Verify an installed Velvet Vixxen update

The owner reported that the previous Media/Composer update left the site unchanged. That affected host is still unverified. The replacement distribution uses **build 20261019.5** so the downloaded file and owner interface identify a specific build while retaining core/add-on compatibility version 0.8.1.

## Applying the complete installed-site update

For an existing site whose exact previous build is unknown, use the complete **Upgrade** ZIP with `Build-20261019.5` in its filename. Extract the ZIP's contents into the active site folder, where the existing `admin`, `app`, `public` and `route.php` are located, and choose to replace existing application files. The archive has no enclosing product directory. The outer GitHub Actions artifact is a collection of ZIPs; extract the intended inner customer ZIP.

The Upgrade omits `config`, `storage`, `uploads` and `install`. It supplies the complete current application runtime. It does not reinstall the site, create a new owner, replace security keys, run SQL or replace saved content. The separate Installer Database Repair is for unfinished setup and does not supply the Composer update.

Sign in to the existing owner account, then open **System → Update Status**. If the old administration page is still cached, open `admin/update.php` directly under this installation's URL. This physical entry point is included in the update.

The page compares every shared runtime file with `includes/runtime-build.json`, a package-generated inventory. It lists missing, unreadable, linked or different files. If files are incomplete, replace the listed application files from the complete Upgrade and check again. Extraction modes such as “skip existing”, “newer only” or “skip equal size” can leave a mixed build; matching file names, sizes or dates do not establish matching contents.

Once the files match, choose **Refresh application PHP**. This action requires the active owner and a valid CSRF token. It invalidates only verified runtime PHP files for this site, preserving the host's other applications and all site data. The page reloads and reports the build PHP is running. Hosts that disable application cache invalidation receive a specific request to have hosting support clear this site's PHP OPcache; the page must not claim success when the operation fails.

Then verify the actual user actions: add a separate button in Visual Composer, upload an image, open Full Page Preview and leave its tab open, publish the selected scene, and follow the new button on the public page. **File/build checks do not by themselves prove these actions work.**

## Engineering evidence and limits

The exact prior saved downloads were retrieved and compared with their source-built and GitHub-gated bytes. They match. The actual older delivered runtime reproduces absent Add Button, Publish 404/parser failure and a disappearing preview tab. Applying the delivered 67-file repair fixes those actions in the retained authenticated browser context under PHP's file server with normal cache behavior.

A separate same-process OPcache reproduction proves why overwriting files can still leave the older UI active: with timestamp validation disabled, corrected file hashes coexist with the old Composer template and old asset revision. The build verification/cache action is tested against that condition. Current test completion and artifact identities belong in `docs/TEST_REPORT.md`; do not infer a green final result from this procedure document.

No production hosting or customer data has been accessed. The owner must provide the affected URL for direct hosted verification. Preserve that unresolved boundary until the actual served files, API responses and browser behavior are observed.

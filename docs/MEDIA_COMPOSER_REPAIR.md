# 0.8.1 Media, Composer and Public Route Repair

This update addresses image uploads returning `File not found.` or a JSON parsing error, Full Page Preview opening and immediately closing, and Publish appearing to do nothing. It also adds separate editable button layers to the Visual Composer and restores public route entry points after the folder reorganization.

## Apply the update to an installed 0.8.1 website

For the current **Build 20261019.5** followup, use the complete installed-site update when the previous build is unknown:

1. Download `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261019.5-Upgrade-from-0.8.x.zip`.
2. Extract its contents into the active website folder containing `admin`, `app`, `public` and `route.php`, replacing existing application files.
3. Sign in as the existing owner, then open **System → Update Status**, or this site's `admin/update.php` directly if the old admin page is still cached.
4. Check that all runtime files match. Missing or different files are listed explicitly; use the complete Upgrade to replace them.
5. Choose **Refresh application PHP** and check that the running build reads **20261019.5**. Then test **Add Button**, image upload, **Full Page Preview** and **Publish** in the existing Composer.

The owner reported that the previous update appeared unchanged, so the affected host remains unverified. See [UPDATE_VERIFICATION.md](UPDATE_VERIFICATION.md) for the new file/cache check and current testing limits.

The repair contains application files only. It preserves the existing configuration, database, owner/member accounts, uploaded photos, saved compositions and installation state. No installer rerun or database migration is needed for this repair. Existing button-free compositions continue to work.

The full fresh-install and upgrade ZIPs also include this correction. Use the fresh package for a new installation and the upgrade package for the complete installed-site update. The separate Installer Database Repair remains for an unfinished installer; it does not contain this Media/Composer update.

## Add and edit buttons

Choose **Hero** or **Footer**, select **Desktop**, **Tablet** or **Mobile**, and click **Add Button**. A button is a separate layer with its own label, destination, appearance and position. It remains a real link on the public website.

Use the selected layer controls to change its text, destination, solid/outline/pill style, fill/text/border colors, text size, corner radius and whether it opens in a new tab. Destinations can be pages on this website, such as `booking/` or `membership/`, or complete `https://` and `http://` links. Site-relative destinations stay inside a subfolder installation when applicable.

Drag a button to position it and resize its width and height independently. Numeric controls, snapping, keyboard movement, ordering, hide/show, lock, duplicate/delete and undo/redo use the existing layer system. Each device stores a separate layout; **Copy to other devices** intentionally copies the selected layout. Buttons outside the Hero/Footer edges are clipped along with other layers. Transparent photos remain separate photo layers.

**Save Draft** stores the selected section. **Full Page Preview** saves both Hero and Footer drafts and opens their owner preview without publishing. **Publish** makes the selected section live and adds a restorable version. If a save fails, the admin displays a readable error and the preview tab stays open with an explanation. A failed action can be retried after the underlying problem is resolved.

## What changed in routing

The reorganized implementation keeps its protected request handlers under `public/routes`. The repaired package supplies thin PHP entry points at their existing public URLs, including upload/scene APIs, account pages, booking, checkout, content pages and protected media. These forward to the same handlers and preserve their authentication, CSRF, entitlement and request-method checks.

The root `media.php` is a small compatibility entry point for existing signed-media URLs; its implementation stays under `public/routes`. Other page entry points stay in their respective folders. The preceding repair contained 67 runtime files (60 PHP, five JavaScript and two CSS). Build 20261019.5 adds the owner update check and package-generated inventory, bringing the focused repair to 72 runtime files; its known baseline requirements are recorded in the separate package manifest. Legacy cleanup recognizes the current forwarding files and privately archives only obsolete code and unknown sibling files; running cleanup no longer removes the active public routes. The four short account aliases also resolve consistently through direct files and the rewrite router. Member sign-in returns, marketing links/buttons, banners and analytics requests also retain the installation base path. Root and nested installations are covered by actual extracted-package tests without a testing router that forwards missing files.

## Verification and provenance

Exact implementation, executed tests, GitHub run state and final package hashes are recorded in [TEST_REPORT.md](TEST_REPORT.md), [RELEASE_MANIFEST.txt](../RELEASE_MANIFEST.txt) and [the installed-build repair record](releases/0.8.1-installed-build-repair.json). The [preceding Media/Composer record](releases/0.8.1-media-composer-repair.json) remains historical evidence. Current release status must be read from those records; this guide alone is not proof that an upload or deployment succeeded.

The repair ZIP excludes configuration, storage, uploads, installer state, database records, repository documentation, tests, workflows, screenshots, SDK source and private data. The package manifest is a separate download. Payments remain sandbox-only, and no deployment to the owner's server is implied by the source or package verification.

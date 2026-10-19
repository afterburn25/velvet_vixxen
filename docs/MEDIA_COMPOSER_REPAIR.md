# 0.8.1 Media, Composer and Public Route Repair

This update addresses image uploads returning `File not found.` or a JSON parsing error, Full Page Preview opening and immediately closing, and Publish appearing to do nothing. It also adds separate editable button layers to the Visual Composer. **Build 20261019.5 was rejected because its routing repair reintroduced the old root folders. Build 20261019.6 restores the approved organized layout and routes generated requests through the existing physical `route.php`.**

## Apply the update to an installed 0.8.1 website

For **Build 20261019.6**, use the complete installed-site update when the previous build is unknown:

1. Download `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261019.6-Upgrade-from-0.8.x.zip`.
2. Extract its contents into the active website folder containing `admin`, `app`, `public` and `route.php`, replacing existing application files.
3. Sign in as the existing owner, then open this site's **`admin/repair.php`** directly. The new entry and verifier bypass the cached .5 update page. The updated **System → Update Status** link opens this page too.
4. Check that all runtime files match. Missing or different files are listed explicitly; use the complete Upgrade to replace them.
5. Choose **Refresh application PHP** and check that the running build reads **20261019.6**.
6. Choose **Restore organized folders**. This removes only the exact known forwarding files from the rejected package and their empty directories. Custom or modified items remain in place and are listed on the page.
7. Test **Add Button**, image upload, **Full Page Preview** and **Publish** in the existing Composer.

An overlay cannot remove files left by an earlier ZIP extraction, which is why the owner repair action is part of this correction. The affected host remains unverified. See [UPDATE_VERIFICATION.md](UPDATE_VERIFICATION.md) for the exact approved root layout, repair behavior and current testing limits.

The repair contains application files only. It preserves the existing configuration, database, owner/member accounts, uploaded photos, saved compositions and installation state. No installer rerun or database migration is needed for this repair. Existing button-free compositions continue to work.

The full fresh-install and upgrade ZIPs also include this correction. Use the fresh package for a new installation and the upgrade package for the complete installed-site update. The separate Installer Database Repair remains for an unfinished installer; it does not contain this Media/Composer update.

## Add and edit buttons

Choose **Hero** or **Footer**, select **Desktop**, **Tablet** or **Mobile**, and click **Add Button**. A button is a separate layer with its own label, destination, appearance and position. It remains a real link on the public website.

Use the selected layer controls to change its text, destination, solid/outline/pill style, fill/text/border colors, text size, corner radius and whether it opens in a new tab. Destinations can be pages on this website, such as `booking/` or `membership/`, or complete `https://` and `http://` links. Site-relative destinations stay inside a subfolder installation when applicable.

Drag a button to position it and resize its width and height independently. Numeric controls, snapping, keyboard movement, ordering, hide/show, lock, duplicate/delete and undo/redo use the existing layer system. Each device stores a separate layout; **Copy to other devices** intentionally copies the selected layout. Buttons outside the Hero/Footer edges are clipped along with other layers. Transparent photos remain separate photo layers.

**Save Draft** stores the selected section. **Full Page Preview** saves both Hero and Footer drafts and opens their owner preview without publishing. **Publish** makes the selected section live and adds a restorable version. If a save fails, the admin displays a readable error and the preview tab stays open with an explanation. A failed action can be retried after the underlying problem is resolved.

## What changed in routing

The implementation keeps all 42 protected request handlers under `public/routes`. Generated public/API requests use the real `route.php` file with one validated `vvx_route` selector; for example, `route.php?vvx_route=api%2Fscene.php&section=hero`. The 87 registered aliases cover upload/scene APIs, account pages, booking, checkout, content pages and protected media. Authentication, CSRF, entitlements, request methods, existing query parameters and signed-media values are preserved.

The root contains only `.htaccess`, `VERSION`, `index.php` and `route.php` as files. Fresh packages have the original 13 root directories; upgrades have nine and omit installed configuration, installer, storage and upload folders. The 25 retired route folders and root `media.php` are absent. Pretty aliases remain supported by the actual web-server rewrite rules where available; generated application requests do not depend on rewrites to missing PHP files.

The focused Build 20261019.6 repair contains 79 runtime files. Its dependency closure is checked against three actual prior delivered package maps, and its supported baselines are recorded in the separate package manifest. Use the complete Upgrade if the installed source is unknown. The owner repair privately preserves exact old forwarding bytes as reversible, nonexecuting JSON data and leaves unknown siblings, modified files and symbolic links in place. The old broad archive API is disabled. Member sign-in returns, marketing links/buttons, banners and analytics requests retain the installation base path. Root and nested installations are covered by actual extracted-package tests without a testing router that forwards missing files.

## Verification and provenance

Exact implementation, executed tests, GitHub run state and final package hashes are recorded in [TEST_REPORT.md](TEST_REPORT.md) and [RELEASE_MANIFEST.txt](../RELEASE_MANIFEST.txt). The [historical-package cleanup acceptance](releases/0.8.1-root-layout-local-acceptance.json), [packaged routing acceptance](releases/0.8.1-root-layout-routes.json) and [application browser record](releases/0.8.1-root-layout-browser.json) identify their tested source and local scope. The preceding installed-build and Media/Composer records remain historical evidence; their passing tests did not enforce the approved folder constraint. Current release status must be read from the current records; this guide alone is not proof that an upload or deployment succeeded.

The repair ZIP excludes configuration, storage, uploads, installer state, database records, repository documentation, tests, workflows, screenshots, SDK source and private data. The package manifest is a separate download. Payments remain sandbox-only, and no deployment to the owner's server is implied by the source or package verification.

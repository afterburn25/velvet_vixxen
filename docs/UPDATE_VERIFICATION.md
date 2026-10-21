# Update and verify Velvet Vixxen — 0.8.14 / Build 20261021.3

<!-- scene-schedules-0814:start -->
## Current 0.8.14 complete Upgrade procedure

**Verified, merged and delivered.** [PR #33](https://github.com/afterburn25/velvet_vixxen/pull/33) completes [issue #32](https://github.com/afterburn25/velvet_vixxen/issues/32). Merge completed at `2026-10-21T17:07:02Z`; literal saved-download read-back completed at `2026-10-21T17:09:54.040414+00:00`. Earlier procedures below retain their historical release identities. Use this procedure, or a later verified release, for the current complete Upgrade.

1. Keep a recoverable backup of the active site files and database. Preserve `config`, `storage`, `uploads`, the owner account, installation identity and keys.
2. Extract **Nikki-Wilder-Velvet-Vixxen-0.8.14-Build-20261021.3-Upgrade-from-0.8.x.zip** into the existing active website folder and replace matching application files. The archive has no enclosing product folder; a subfolder installation receives files in that same subfolder.
3. Sign in as the existing owner and open **System → Update Status** (`admin/repair.php`) under that installation's URL. Require **Update files verified**, **Uploaded package: v0.8.14 · Build 20261021.3**, and **Files needing replacement: 0**. Complete extraction of the same full Upgrade if files differ.
4. Choose **Refresh application PHP**. After reload require **PHP running now: 20261021.3** and repeat the file checks. Reload the admin/public pages with asset revision **0.8.14-20261021.3**; inspect the site's browser/hosting/CDN cache if old responses persist after file and PHP checks pass.
5. Use **Restore organized folders** only when the page detects the exact retired forwarding files/folders from rejected Build 20261019.5. It preserves modified/custom entries and removes known directories only when empty; no action is needed without detected retired entries.
6. Open **Site & Design → Scene schedules** and inspect **Homepage Hero** and **Visual Footer**, the saved draft title and displayed site timezone. Follow [Scheduled Hero and Visual Footer scenes](SCENE_SCHEDULES.md) when deliberately scheduling content. **Schedule** in Composer saves both drafts, then opens the selected section. **Schedule saved draft** freezes that saved composition. Opening either page or **Marketing & Publishing → Calendar** does not publish it.
7. Enter **Starts at**, optionally **Ends at (optional)**, and choose **After the end time** deliberately. **Run due scenes now** checks both sections and can publish due schedules; use it only when ready for their changes. Unattended operation requires the host's cron configuration described in the owner guide. The package does not configure or verify that host service.
8. Preserve and review the existing Menus, per-device Composer layouts, compact footer, depth/foreground, public pages, calendar, post expiration and history/restore workflows. For older pending database/marketing updates or add-on recovery holds, use the existing owner-reviewed workflows. This increment adds no database migration.

Complete Fresh/Upgrade packages contain **385 / 344 runtime entries**. The separate unfinished-installer repair remains **0.8.1 / repair Build 20261020.3**, unchanged at 11 files. Use it only for unfinished 0.8.1 setup, never as the installed-site Upgrade. Production email, live payment approval and actual-host #12/#13 remain unverified; payments remain sandbox-only. The saved-package proofs establish the delivered bytes, not deployment on a customer host.

[Release record](releases/0.8.14-scene-schedules.md) · [CI evidence](releases/0.8.14-scene-schedules-ci.json) · [Saved-delivery evidence](releases/0.8.14-scene-schedules-delivery.json)
<!-- scene-schedules-0814:end -->

<!-- editorial-calendar-0813:start -->
## Current 0.8.13 complete Upgrade procedure

**Verified, merged and delivered.** [PR #31](https://github.com/afterburn25/velvet_vixxen/pull/31) completes [issue #30](https://github.com/afterburn25/velvet_vixxen/issues/30), the bounded calendar and post-expiration increment of broader #9. Merge completed at `2026-10-21T09:43:43Z`; literal saved-download read-back completed at `2026-10-21T09:45:50.359883+00:00`. Earlier release blocks preserve historical evidence and their then-current continuation notes.

Complete Fresh and Upgrade packages contain **378 / 337 runtime entries**. Use the complete Upgrade for an existing 0.8.x installation. Approved package roots and installed configuration, storage, uploads, owner identity and keys are preserved; no new migration is added. The separate unfinished-installer repair remains byte-identical at **0.8.1 / repair Build 20261020.3**.

1. Keep a recoverable backup of the active site files and database. Preserve `config`, `storage`, `uploads`, the owner account, installation identity and keys.
2. Extract the complete **Nikki-Wilder-Velvet-Vixxen-0.8.13-Build-20261021.2-Upgrade-from-0.8.x.zip** into the existing active website folder and replace matching application files. The archive has no enclosing product folder; a subfolder installation receives the files in that same subfolder.
3. Sign in as the existing owner and open **`admin/repair.php`**, also available through **System → Update Status**, under that installation’s URL.
4. Require **Update files verified**, **Uploaded package: v0.8.13 · Build 20261021.2**, and **Files needing replacement: 0**. Complete extraction of the same full Upgrade if files differ; the archive name alone does not establish an installed runtime.
5. Choose **Refresh application PHP**. After reload require **PHP running now: 20261021.2** and repeat the file checks. Reload the admin/public pages with asset revision **0.8.13-20261021.2**; if old responses persist after verification, check that site’s browser/hosting/CDN cache.
6. Only if the page detects the exact retired forwarding files/folders from rejected Build 20261019.5, use **Restore organized folders**. Modified/custom items remain protected; no action is needed when none remain.
7. Open **Marketing & Publishing → Calendar**. Check the selected month/type, site timezone, agenda and undated/review entries. Follow links to the existing editors; merely opening the calendar must not publish content or send email.
8. Edit a post and verify optional **Expires at**, site-timezone guidance and saved dates. A blank expiration has no end; a dated expiration must follow a dated publication. A draft stays hidden. Review **History** before a deliberate restore; restoration leaves Draft and an older version without expiration does not inherit a newer bound.
9. Verify retained Menus, matching Composer/public Hero and Visual Footer scale, independent photo depth/foreground controls, larger compact footer and blue new-tab Cajun Web Pro credit. Check bottom placement on short pages and normal scrolling on long pages at desktop/tablet/mobile widths. Existing saved scenes and menus must remain intact.
10. Use the existing owner update/recovery workflows for any older pending database or add-on work. This increment adds no migration. A package overlay does not certify production email, change sandbox payment status or prove the affected host’s behavior.

All **seven required jobs and 78 configured required steps** passed in [CI run 39573078884](https://github.com/afterburn25/velvet_vixxen/actions/runs/39573078884), retaining the prior 74 steps in order. The actual checkout in every job was `0bb06c99ddde81ab701e7df3409b2152247451d0`. Source `79a8d305e58e849c7489ba557f3cf10cffc8504d`, that checkout and final merge `6faf826c0a3c6f252c876efbd2e5e8de35ee178f` share complete Git tree `0bc4dda8f6fff355009120b4f76557b319142bc2`. Downloaded runtime/browser artifacts are bound to the run and committed source. All four saved customer files were read back and match the gated artifact by literal bytes, size and SHA-256. No separate post-merge CI run or customer-host installation is implied.

Keep **#3** open as the continuing release-evidence policy and broader **#9** open for the remaining marketing capabilities. Automatic Composer scene activation/expiry, deleted-item recovery, campaign-content preview/test-send/templates/tracking, additional audience predicates, referral attribution, analytics operations, missing notifications and provider bounce/complaint handling remain separate. Composer dates in this calendar are retained publication history and drafts; no scene scheduler is delivered. Actual-host **#12/#13** and production email remain unverified; payments remain sandbox-only and the separate **0.9 milestone remains incomplete**.

[Calendar guide](EDITORIAL_CALENDAR.md) · [Release record](releases/0.8.13-editorial-calendar.md) · [CI evidence](releases/0.8.13-editorial-calendar-ci.json) · [Saved-delivery evidence](releases/0.8.13-editorial-calendar-delivery.json)
<!-- editorial-calendar-0813:end -->

<!-- composer-depth-0812:start -->
## Current 0.8.12 complete Upgrade procedure

**Verified, merged and delivered.** [PR #29](https://github.com/afterburn25/velvet_vixxen/pull/29) addresses [issue #28](https://github.com/afterburn25/velvet_vixxen/issues/28). The final release merged on `2026-10-21T06:51:53Z`; literal saved-download read-back completed on `2026-10-21T06:55:05.291536+00:00`. Earlier release blocks remain historical records with their then-current continuation notes.

This release adds independent photo depth and foreground furniture without a new migration. It includes the delivered menus, scene-scale and compact bottom-footer behavior. The older 0.8.11 instructions below are explicitly historical; their pending-depth notes and old archive/build identities do not describe this release.

1. Keep a recoverable backup of the active site files and database. Preserve the existing `config`, `storage` and `uploads` directories, owner account, installation identity and keys.
2. Extract the complete **Nikki-Wilder-Velvet-Vixxen-0.8.12-Build-20261021.1-Upgrade-from-0.8.x.zip** into the existing active website folder and replace matching application files. The archive has no enclosing product folder. A subfolder installation must receive these files inside that same subfolder.
3. Sign in with the existing owner account and open **`admin/repair.php`**, also available through **System → Update Status**, under that installation's URL.
4. Require **Update files verified**, **Uploaded package: v0.8.12 · Build 20261021.1**, and **Files needing replacement: 0**. A matching ZIP filename alone does not prove a complete installed runtime. If anything is missing or different, complete extraction of the same full Upgrade and verify again.
5. Choose **Refresh application PHP**. After reload, require **PHP running now: 20261021.1** and repeat the file checks. If PHP caching is disabled, the running build must still match. Reload Composer and the public page with asset revision **0.8.12-20261021.1**. If old responses remain after the file/PHP checks pass, hard-reload or inspect that site's hosting/CDN cache.
6. Only if the page detects the exact retired forwarding files/folders from rejected Build 20261019.5, use **Restore organized folders**. That retained repair handles the known 46 forwarders and removes their 25 directories only when empty. Modified/custom items remain protected; review any reported exceptions. No action is needed when no retired entries remain.
7. Open **Visual Composer** and follow [Photo depth and foreground furniture](COMPOSER_DEPTH.md). In Hero and Visual Footer, check the current Desktop, Tablet and Mobile layouts. Increase the selected photo's depth, draw/finish a furniture outline, and enable **Place behind foreground** only for the chosen photos. Verify direct/numeric points, Undo/Redo and Reset depth; finish or cancel unfinished drawing before saving.
8. Save the draft and reload it. Use **Full Page Preview** to compare the visible crop, photo geometry and furniture edge with Composer at the matching device layout. Saving and previewing do not publish. Deliberately choose **Publish** when ready, compare the public page, and verify retained published versions through the existing history/restore controls.
9. Verify the retained Menus editor, grouped/standalone buttons, real image upload, larger compact footer, blue new-tab Cajun Web Pro credit, bottom placement on short pages and normal scrolling on longer pages. Check desktop, tablet and mobile, including common public pages. The historical feature checks below and their linked current guides provide the detailed retained workflows.
10. If this installation has older pending database/marketing updates or an add-on recovery hold, use its existing owner update/recovery workflows. This 0.8.12 increment adds no new migration. A package overlay does not establish production email delivery, change sandbox payment status, or prove host-specific behavior.

The Upgrade has **334 runtime entries**, excludes `config`, `storage`, `uploads` and `install`, and preserves saved content. It does not reinstall the site, create a new owner, replace keys or run SQL. `app/Core/SceneDepth.php` and `public/assets/js/scene-depth.js` are required new dependencies; partial overwrites fail the complete runtime inventory. The separately targeted 0.8.1 / Build 20261020.3 installer repair is unchanged and is not the installed-site Upgrade.

The final [required CI run 39553690388](https://github.com/afterburn25/velvet_vixxen/actions/runs/39553690388) passed **all seven required jobs and 74 configured required steps**, retaining the prior 70 steps in order. The actual checkout in all seven jobs was `c18a80d79e9f5559bdfb2d43f25f3065dfb93c06`. Source `a52747b1b20d8530b328255af740d72821eab70f`, that checkout, and final merge `e7b6938162b15a0371baca97fbf195c14acae569` have the identical complete Git tree `c09486d5f46021d7e021ed67a8fbb3604c42b43c`. The downloaded runtime and browser artifacts are bound to that source/run; all four saved customer files were read back and match the gated artifact by literal bytes, size and SHA-256. This evidence does not claim a separate post-merge CI run.

Keep #3 open as the continuing release-evidence policy. Broader #9 calendar, explicit post expiry and other marketing work remain separate. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only and the separate 0.9 milestone remains incomplete. This repository release and saved delivery do not establish installation or behavior on the affected customer host.

[0.8.12 release record](releases/0.8.12-composer-depth.md) · [CI evidence](releases/0.8.12-composer-depth-ci.json) · [saved-delivery evidence](releases/0.8.12-composer-depth-delivery.json)
<!-- composer-depth-0812:end -->

# Historical 0.8.11 installation instructions and retained feature checks

**Historical release identity below:** the following text records 0.8.11 / Build 20261020.12. Use the current 0.8.14 complete Upgrade procedure above for archive/build checks. The retained feature checklists remain useful where their behavior still applies.

Version **0.8.11**, Build **20261020.12**, adds the owner Menus editor for Header/Footer links and submenus. It also keeps short-page footers at the viewport bottom and removes unnecessary small scroll ranges. It includes the preceding Composer scale and larger compact footer corrections, along with the previously delivered application features. No new migration is needed. The unfinished-installer repair retains its separate **0.8.1 / repair Build 20261020.3** identity.

**Verified release:** 0.8.11 / Build 20261020.12 passed all seven required CI jobs/70 configured steps, merged through PR #27 at `6d7cfee8936a30c1b1acff47ca55c9e91d9e644b` on `2026-10-21T01:48:45Z`, and passed literal saved-download read-back at `2026-10-21T01:55:10.007674+00:00`. The [release record](releases/0.8.11-menus.md), [CI record](releases/0.8.11-menus-ci.json) and [delivery record](releases/0.8.11-menus-delivery.json) bind the actual source, checkout, merge and package bytes. This guide verifies the delivered complete Upgrade; the affected host still needs its own installation and behavior checks.

Rejected Build **20261019.5** reintroduced 25 public route folders and 46 forwarding PHP files at the website root. Build **20261019.6** supplied the approved restoration and safe owner repair. That repair remains included in this release; its historical CI/delivery records remain available.

## Existing 0.8.x installation

Keep a recoverable backup of the current site files and active database before applying an update. Preserve the existing `config`, `storage` and `uploads` directories, installation identity and owner account. Use the complete existing-site Upgrade; the Fresh Install package and unfinished-installer repair serve different installation states.

1. Extract the contents of the complete **Nikki-Wilder-Velvet-Vixxen-0.8.11-Build-20261020.12-Upgrade-from-0.8.x.zip** into the existing active website folder. Replace matching application files. The archive has no enclosing product folder; an installation under a subfolder must receive the files inside that same subfolder.
2. Sign in with the existing owner account and open **`admin/repair.php`** under this installation's URL. This entry remains usable when an older update page or PHP classes are cached. **System → Update Status** also opens this page.
3. Require **Update files verified**, **Uploaded package: v0.8.11 · Build 20261020.12**, and **Files needing replacement: 0**. If files are missing or different, complete extraction of the same full Upgrade and reload. The page verifies the actual files against the package inventory; the archive filename alone does not establish what is installed.
4. Click **Refresh application PHP**. After the page reloads, require **PHP running now: 20261020.12** and repeat the file checks above. If PHP caching is disabled, the page reports that condition after file verification; the running build must still match. Reload Composer and the public page so their HTML and versioned CSS/JavaScript requests use asset revision **0.8.11-20261020.12**. If an older page remains, hard-reload it or check a fresh browser window; review this site's hosting/CDN cache only if it still serves old responses after the file and PHP checks pass.
5. If the page detects forwarding files or empty route folders left by rejected Build .5, click **Restore organized folders** after file/build verification. The repair retires only the exact 46 known forwarding files and removes their 25 directories only when empty. Review its result and any custom or modified items preserved. If the page already reports that no retired compatibility files or folders remain, no folder-repair action is needed.
6. Complete **Verify the Menus editor**, **Verify Composer and public scene scale**, **Verify the readable, compact footer**, and **Verify short-page footer placement and scrolling** below on the homepage and a common public page such as **About Me**.
7. Complete the retained grouped-button checks below, including a real image upload, the existing standalone **Add Button** action, retained **Full Page Preview**, and deliberate **Publish**.
8. Open **Media Center**, edit and save a gallery caption/tag and its order, then reload and confirm exact retained photographs. Check playlist order and dates. Follow [the Media Center guide](MEDIA_CENTER.md) for stale-form recovery and the owner action or hosting scheduler that runs due publication.
9. Open **Add-ons** and review any recorded recovery hold. Follow [the Add-on recovery guide](ADDON_RECOVERY.md); an uncertain database outcome requires review, and schema rollback/purge cannot restore deleted data without a database backup.
10. Open **System → Email & SMTP** and verify the owner settings using the steps below. Then check the campaign controls if you use newsletter campaigns.
11. If an installed MySQL site still has pending marketing migrations, open **System → Database Updates**, review/apply them, then follow the separate **Marketing Storage** move below if you want to move the marketing records. Applying the tables alone leaves the marketing file active. This 0.8.11 increment adds no migration.
12. Use the editorial metadata checks below to verify a saved post and landing page, including their public and **Preview Saved** views. Existing private history and restore remain available as described in the [Editorial history guide](EDITORIAL_HISTORY.md).

The Upgrade excludes `config`, `storage`, `uploads` and `install`. It does not reinstall the site, create a new owner, replace keys, run SQL or replace saved content. A ZIP overlay alone cannot remove folders from an earlier extraction; an installation still carrying the rejected .5 folders needs the verified owner repair action.

The repair preserves the exact old forwarding bytes as base64 JSON under protected private repair storage. It does not leave executable PHP backups. Unknown or changed files, symbolic links and custom subfolders are preserved in place. A folder containing one will remain and be listed. Failed filesystem operations are reported and can be retried; no broad recursive cleanup or world-writable permissions are used.

## Verify the Menus editor

Open **Site & Design → Menus** as the active owner. Edit an item, choose Same tab/New tab/New window, add a submenu, reorder it and save. Verify Header and Footer on a public desktop and phone page; use a real click to check the destination and opening choice. Browser policies may choose a tab or block a requested window. See [the Menus guide](MENUS.md) for empty menus, hiding branches, stale edits and restoring defaults. Existing account/cart/legal/designer controls remain separate. This increment uses existing settings storage and adds no migration.

## Verify Composer and public scene scale

1. Open an existing **Hero** in Visual Composer and inspect its **Desktop**, **Tablet** and **Mobile** layouts. The update must retain the saved height, background, photo positions and intentional edge crops. An unchanged saved scene needs no resave for the correction to apply.
2. Open **Full Page Preview** to compare the saved draft with its full-page rendering. Use the same device layout on both sides: public widths above 1120px use Desktop, 761–1120px use Tablet, and 760px or less use Mobile. Representative browser widths are **1440px**, **900px** and **390px**. The editor may display a smaller canvas to fit its panel; its proportions and visible composition must match the full page.
3. Compare the background crop and a recognizable point near the photo's lower edge. A point visible inside the Composer frame must remain inside the corresponding public frame. Resizing within one device range should scale the entire scene together, including text, buttons and effects, rather than cut off more of the photo. Content deliberately positioned outside the frame remains cropped. The sticky navigation sits outside the Hero and is not part of the scene-height comparison.
4. Check the live headline and buttons in **Full Page Preview**. The pink **Live text + buttons** rectangle in Composer is a positioning guide; the preview shows the actual copy and button wrapping. Return to Composer and confirm that previewing and resizing have not changed the saved positions or published the draft.
5. When the draft is ready to become public, deliberately choose **Publish**, then reload the public page and compare the same published composition at desktop, tablet and mobile widths. Repeat for the **Visual Footer** scene if used. A newer saved draft can legitimately differ from the public page until published.

The expected frame height is the saved design height multiplied by the available section width divided by its device design width: **1440 / 900 / 390px** for Desktop / Tablet / Mobile. For example, a Desktop scene saved at 760px high should render about 886.67px high at 1680px wide. The [Composer guide](VISUAL_COMPOSER.md) explains the scene controls; new depth-placement and foreground-masking tools remain separate pending work.

## Verify the readable, compact footer

1. Scroll to the bottom of the homepage and **About Me**. Check that the brand, subtitle, signature and all visible footer links are larger and readable. Navigation, legal links and the designer credit use **16px** text. The bottom legal footer is separate from the **Visual Footer** scene and does not alter that scene's saved height or layers.
2. Confirm **Website designed & developed by Cajun Web Pro LLC** appears, with **Cajun Web Pro LLC** visibly blue and underlined. Click it and require **https://cajunwebpro.com/** to open in a new tab while the original site remains open. Check that the link can also be reached with the keyboard.
3. Check desktop and narrow mobile widths. Footer text should wrap without colliding or spilling outside its own footer. The footer has no fixed minimum height, uses **8px** of padding above and below and **4px** between rows, and grows only to fit its content. Enlarged text can require extra rows on a narrow phone. Existing responsive hiding of navigation and legal links is preserved.
4. If the audio player is visible, scroll fully to the document bottom and confirm that the entire credit link sits above it and responds to an ordinary click. Resize to a narrow phone width and repeat. The page reserves the player's actual height plus 8px below its content; that clearance is outside the footer itself. On a page whose public catalogue has no available tracks, the player should remain hidden without a reserved player-height gap. Existing tracks need not be removed to check this state.

## Verify short-page footer placement and scrolling

1. Open a short public page and check desktop and phone widths. If its complete content fits, the footer should sit at the bottom of the available screen and a wheel or swipe should not reveal an extra sliver of empty space.
2. Repeat while changing the browser's height. With the audio player visible, the footer ends above its measured height plus 8px of clearance. A hidden or omitted player leaves no reserved player gap.
3. Open a longer page and expand any custom Footer submenus. The document must still scroll normally, and the entire footer and designer link must remain reachable at its end. Large text, smaller screens or additional menu rows can legitimately make a page taller than one screen.

## Verify Button 3 inside Live Text + Buttons

1. Open **Visual Composer**, select the **Hero**, and click the **Live Text + Buttons** box directly on the canvas. The right inspector opens the live text/button-box controls and **Buttons in this group**.
2. With the existing two Hero buttons present, click **+ Add button to this group**. Confirm that a **Button 3** editor appears in the same right panel. The toolbar's existing **Add Button** still creates an independently movable button layer.
3. In **Button 3**, choose **Website link**, enter a **Button label**, set its destination (for example `booking/`), and choose its **Button style**. Verify the third button appears with the live text. Use the arrow or **Remove** controls as needed; undo/redo must restore those changes.
4. Drag or resize the live text/button box and check **Desktop**, **Tablet** and **Mobile**. Labels, destinations and button order are shared; the box position and size remain separate for each device. The buttons wrap within the group, while transparent photo cutouts and standalone buttons retain their independent layers.
5. Click **Save Draft**, reload Composer, select the group again and confirm **Button 3** and its values remain. Footer groups use the same controls; their existing button count determines the next number.
6. Click **Full Page Preview**. Its tab must finish loading and remain open with the saved Hero and Footer drafts. Check the third button and its destination in the full page. If the browser blocks the popup, allow this site's popup and use **Full Page Preview** again.
7. Return to Composer and click **Publish** for the composition you changed. Open the public page, confirm the grouped button is present, and follow its link. Check the layouts on desktop and mobile. Published history must retain the grouped buttons when a saved version is restored.

## Verify owner Email & SMTP settings

1. Open **System → Email & SMTP** as the active owner. The page at `admin/?page=mail-settings` shows the saved connection mode and transport status. The Upgrade preserves existing mail configuration.
2. Choose **Disabled**, **SMTP**, or **PHP mail** and review the sender details. For SMTP, use the provider's host, port, security mode and username. An explicit valid From address takes precedence; a blank From uses an email-shaped SMTP username, then the owner email fallback. A blank password field retains the saved password; the separate **Remove the saved SMTP password** control clears it when saved. The saved credential is never displayed.
3. Click **Save Email Settings**, reload the page and check that the values and status remain. Saving does not connect to the provider or send a message. If another request changed the configuration, reload before saving again.
4. To verify a configured connection, save all edits first and click **Send Test Email**. This sends one fixed diagnostic to the current owner account email shown on the page. **Test accepted** means the transport accepted it; check the inbox to confirm arrival. An unknown result is held without automatic retry, so check the provider and inbox before deliberately testing again. Saving settings clears an earlier result.

The test uses a single-use token and a 60-second per-session cooldown. The [owner email guide](EMAIL_SETTINGS.md) explains the connection, password, test-result and troubleshooting behavior. Account email, booking notifications and campaign delivery use this shared transport while retaining their own feature preferences.

## Campaign audiences, settings and bounded delivery

Open **Marketing & Publishing → Campaigns** as the owner. **Campaign delivery** describes the saved shared mail transport. Configure that connection in **System → Email & SMTP**; the Upgrade preserves the private installation configuration. If delivery is held by disabled or incomplete mail settings, save and verify the connection there before running a batch.

### Choose and preview the audience

1. Set **Public newsletter signup and confirmation** and **Campaign email delivery** independently, then click **Save Marketing Settings**. Turning off signup hides signup and confirmation routes while existing unsubscribe links remain available. Turning off campaign delivery holds campaign work.
2. For a saved audience, open **Audiences**, enter **Audience name**, then choose at least one signup source or confirmation date. **Exact signup sources, one per line** matches the saved, case-sensitive values shown under **Saved signup sources**. A contact can match any listed source and must also meet every entered date criterion. **Confirmed on or after** includes its starting moment; **Confirmed before** excludes its ending moment. Dates use the displayed site timezone and the contact's latest newsletter confirmation.
3. Click **Preview Audience** to check the current eligible count, then **Save Audience** to save the definition. Previewing saves nothing and queues no messages. Leave **Available for new campaigns** checked to offer this audience in campaign forms. An eligible contact must still have confirmed consent and pass suppression checks.
4. Return to **Campaigns**. Under **Email Campaign**, enter **Subject**, **Message** and an optional **Preheader**. Choose a saved audience or **All eligible newsletter contacts** in **Newsletter audience**, then use **Preview Campaign Audience** to review the matching count. This preview preserves your entered message and does not save or queue it.

Previewing is optional. When the current form contains a preview, queueing checks that preview again. If the audience or eligible recipients changed, refresh the preview and review the new count before retrying. A preview from an earlier page visit does not carry forward automatically; preview again after reopening the form. Queueing without a preview still applies the selected rules, current confirmation, consent and suppression checks.

### Save a draft or queue recipients

Enter an optional **Schedule** in the displayed site timezone. Leave it blank for queued work that may run now. To keep a draft, leave **Queue for confirmed subscribers in this audience** unchecked and click **Save Campaign**. Reopen it through **Edit campaign draft** on its saved card. If its saved audience has since changed, been disabled or been removed, select an available audience again before queueing.

To save and queue together, check **Queue for confirmed subscribers in this audience** and click **Save Campaign**. If queueing finds a stale preview after saving the draft, the page says **“The campaign draft was saved, but no new recipients were queued.”** Use **Preview Campaign Audience** again, review the count, then save with the queue option selected. The retained form updates the same draft.

On a saved campaign, **Preview Recipients** reports both new eligible recipients and addresses that already have delivery records. **Queue Recipients** explicitly adds the new matches. When that card contains a preview, it is checked again before any queue changes. Existing legacy prepared deliveries also require this explicit queue action before delivery.

The first successful queue freezes the message text, audience rules and selected addresses, even if it finds zero eligible recipients. Editing an audience, clearing **Available for new campaigns**, or using **Remove Audience** does not rewrite an already queued campaign. Create a new campaign for different message text or rules. Later confirmations are added only through another explicit **Queue Recipients** action, using that campaign's frozen rules and current eligibility. Requeue does not duplicate existing addresses or reset their delivery outcomes. Use **Pause Campaign** to hold waiting deliveries.

### Run and inspect delivery

1. When the transport is ready, choose **Messages per batch** from 1 to 10 and click **Run Due Campaign Batch**. Only explicitly queued, due campaigns can run. Previewing, saving an audience and queueing do not themselves transmit messages. Every campaign message includes an unsubscribe link.
2. Check the campaign's queued, processing, accepted, failed, unknown and suppressed counts. Accepted means the mail transport accepted the message; it does not confirm arrival in an inbox.
3. Use **Pause Campaign** to stop waiting work and **Resume Campaign** when ready. Pause remains usable from another owner tab while a batch waits on SMTP. Consent, suppression and campaign state are checked again immediately before transmission. A message already transmitting cannot be recalled.
4. Inspect **Delivery problems** before choosing **Retry Failed Deliveries**. Only definite failures can be explicitly retried, with cooldowns and a three-attempt limit. Accepted and unknown outcomes are not retried; an unknown acknowledgement may mean the transport already accepted the message.

For scheduled hosting execution, run `php app/Console/run_marketing.php --limit=10` from this installation's directory, or use its absolute script path in the hosting scheduler. The CLI accepts 1–25 messages per invocation and uses the same schedule, consent, pause, locking and retry checks. Saving a campaign schedule does not create a hosting cron job; the scheduler must invoke the command for future work to run.

See [Newsletter audiences](NEWSLETTER_AUDIENCES.md) for criteria, edit behavior and limits.

## Verify saved posts, promotion banners and landing pages

Open **Marketing & Publishing → Posts**, **Banners**, or the **Campaign Landing Page** area under **Campaigns** as the owner. Dates use the displayed site timezone. Visibility updates on the next public request; no publication cron job is needed.

### Edit the same saved item

Choose **Edit**, change the text, then use **Save Post**, **Save Banner** or **Save Page**. Confirm that saving and reloading reopen the same item without a duplicate. Posts and landing pages keep their **Slug** and **View** address. Verify saved paragraph breaks, banner **Link**, and landing-page **Button label** and **Button link**.

### Check publication times and windows

| Item | Owner controls | Expected public behavior |
| --- | --- | --- |
| Post | **Status**, **Publish at**, **Save Post** | `draft` stays hidden. A `published` post with a future date is **Scheduled** and becomes **Active** at that moment. A published post with a blank date is visible immediately. |
| Banner | **Enabled**, **Starts**, **Ends**, **Save Banner** | Before the start it is **Scheduled**; from the start until the end it can be shown; at the end it is **Ended** and disappears. |
| Landing page | **Enabled**, **Starts**, **Ends**, **Save Page** | Its **View** address is unavailable before the start and from the end onward. It is available during the enabled window. |

Use a short window on a verification item to check before, during and after behavior. The start is included and the end is excluded. A blank start or end leaves that side open. **Ends** must be later than **Starts** when both are present; equal or reversed times must be refused without changing the saved item.

Overlapping banners retain saved order. The first active banner allowed for the current visitor is shown; later eligible banners wait behind it. The owner list distinguishes guest precedence from the order for eligible signed-in members. Editing keeps this order. Check both a guest browser and an eligible member, and disable the banner shown to that viewer to check the next eligible one. Use a browser that has not dismissed the promotion.

### Withdraw, restore and remove

**Move to draft** hides a post; **Publish** honors its saved date, so a future post remains scheduled. **Disable** withdraws a banner or landing page; **Enable** honors its saved window, including an end that has passed. These controls retain content, links and dates.

Use **Delete** on a disposable verification post and confirm that its row and **View** address are gone while other posts remain. Banners and landing pages have **Disable** as their visible withdrawal control.

### Check stale edits and older dates

Open an item in two tabs. Save in the first, then try saving or changing visibility from the older tab. It must refuse the stale request and preserve the newer version. The rejected editor keeps your attempted text; reload the current item before reapplying it. A site timezone change also requires reloading. Edits to other items do not invalidate the form.

Invalid nonempty saved dates hold the item out of public view as **Needs review**. **Edit** shows the original value for repair. Enter a real local value in `YYYY-MM-DDTHH:MM` format, or deliberately clear it using the blank-date behavior above. Impossible dates, relative phrases and skipped daylight-saving times are refused. **Move to draft** or **Disable** remains available; publishing or enabling requires repaired dates.

Valid older dates retain their meaning: absolute timestamps keep their instant, local dates use the site timezone, and date-only values mean local midnight. An unchanged valid date retains its saved instant, including during a repeated daylight-saving hour.

See [Publication windows and saved promotion editors](PUBLICATION_WINDOWS.md) for the complete owner workflow.

## Verify editorial access and saved-owner previews

1. Open **Marketing & Publishing** and edit a saved post, banner or landing page. Under **Who can view this?**, choose **Public**, **Members** or **Selected tiers**. For selected tiers, choose at least one current enabled plan, then save. Members includes active verified free accounts. The rule takes effect with the publication's existing status and date window.
2. Reload the editor and confirm the saved access mode and selected plans. Use a guest browser and the appropriate signed-in test accounts to check the public page. A hidden post is omitted from the listing; an ineligible detail or campaign request receives a generic not-found page. A more expensive plan does not automatically include another plan's editorial content.
3. From the editor or list, choose **Preview Saved**. Confirm the saved content and status, including a draft or future publication. Unsaved form edits are not included. Preview links are inert, and **Back to Edit** returns to the editor. The preview does not publish or record a marketing visit.
4. Verify that a free active verified member can see Members content but cannot see a Selected tiers item without an eligible selected subscription. Lost verification, revoked devices and deactivated accounts must remove restricted access on the next request. Disabled plans, expired subscriptions and ended grace periods remove the corresponding tier access; an otherwise active verified account retains Members access. A malformed or unavailable selected rule stays held until deliberately repaired.

Use the [Editorial access guide](EDITORIAL_ACCESS.md) for the exact access, timing, cache and compatibility rules. These checks apply to both file storage and explicitly activated MySQL marketing storage; this update does not change the active backend.

## Verify editorial metadata

1. Open **Marketing & Publishing → Posts** and edit a saved verification post. Enter an **Author display name** and comma-separated **Tags**. These are public values; the byline does not copy an account name or email. Save and reload, and confirm the saved ID, slug, category, status, date and access setting are retained.
2. Under **Featured image**, select an existing public image and enter **Image description**. Save and confirm that the image loads on the allowed public post and in **Preview Saved**. Protected originals are unavailable even if they have public thumbnails. Featured artwork remains a public asset when selected for a member-only publication.
3. Enter **Search title** and **Search description**. Confirm that the editor's sample updates, then save and reload. Check the allowed public page's browser title and description metadata while its visible heading stays the publication title. Empty search fields fall back to the ordinary title/excerpt. A denied request must retain the generic not-found response without private search text.
4. Select up to six distinct **Related updates** in the desired order, save and reload. Check guest and eligible member views: each shows only currently published related posts allowed for that visitor, in the saved order. A draft or future selection stays unpublished. **Preview Saved** shows saved related content with inert links and excludes unsaved editor text.
5. Under **Campaigns**, edit a saved landing page and verify the same featured-image and search fields. Banners retain their existing controls. Check desktop and mobile layouts. Saving metadata must preserve access, publication dates, saved permalinks and unrelated marketing records.

Use the [Editorial metadata guide](EDITORIAL_METADATA.md) for field limits, existing-image requirements, safe fallbacks and file/MySQL preservation rules. Previewing does not publish content, record marketing visits or send email. Private [editorial history/restore](EDITORIAL_HISTORY.md), delivered in 0.8.9, remains included. A calendar and explicit post expiry remain separate next work.

## Private-reply email notices

The correction introduced in **0.8.5 / Build 20261020.6** remains included: private reply subjects, message bodies and attachment names stay out of notification emails. Member notices link to the conversation after sign-in. Guest booking notices ask the recipient to contact the site to follow up; they do not promise guest access to an authenticated conversation. The complete reply stays on the site, and internal notes send no email.

## Review and move Marketing Storage

Open **System → Marketing Storage** as the active owner. If it says **Update the database first**, use **Open Database Updates**, review/apply the pending migration, and return. The update creates the two storage tables without importing records or activating SQL.

Select **Preview Move** and review the aggregate totals. The review covers the full current source and destination state, including private fields that do not change record counts. Select **Move to MySQL** to copy, verify and activate the records, or **Cancel Review** to discard the review. A changed source, an expired review or a failed move requires a fresh preview. The current owner is checked again after waiting for locks and before activation.

After a successful move, require **Marketing is stored in MySQL**, then use **Open Marketing** to continue. Existing saved audiences, consent, suppressions, tokens, publishing records, frozen campaign messages and delivery outcomes remain. Moving records does not queue, release or send campaign email. The original file remains unchanged; subsequent marketing edits are stored in MySQL.

If the page reports **Storage needs attention**, use its explanation to review the database and configuration. A required but unavailable or inconsistent SQL backend does not fall back to the old file. A definitely unactivated move with an unchanged original source may offer a new preview for an explicit retry. File-mode sites continue to use file storage.

The retained pre-move file is not a backup of later SQL edits. Include the active database in normal site backups. See the [Marketing Storage guide](MARKETING_STORAGE.md) for the full workflow and recovery boundaries.

## Approved package layout

| Variant | Allowed root directories | Root files |
| --- | --- | --- |
| Fresh installation | `addons`, `admin`, `app`, `config`, `database`, `images`, `includes`, `install`, `public`, `storage`, `templates`, `uploads`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |
| Existing-site Upgrade | `addons`, `admin`, `app`, `database`, `images`, `includes`, `public`, `templates`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |

Public pages and APIs are implemented under `public/routes`. Generated internal URLs use the real `route.php` dispatcher, so actions can execute on root or subfolder installations without relying on rewrites for missing PHP files. Existing pretty paths remain aliases through the real `.htaccess` rules. Old external pretty links need working web-server rewrite handling; the package does not pretend a removed PHP file is still physically present.

## Evidence and limits

The preserved installed-update regression reconstructs the exact rejected Build .5 ZIP from its pinned source/hash, keeps the same browser and PHP process through extraction, proves that extraction alone leaves the unwanted folders, refreshes through the owner entry and performs real cleanup and application actions. Separate package tests reject the actual .5 root maps and check the exact approved root names.

The delivered **0.8.11 / Build 20261020.12** has verified source/checkout/merge equality, all seven successful CI jobs/70 required steps, actual runtime/browser artifact comparisons and literal saved-readback at `2026-10-21T01:55:10.007674+00:00`. The actual menu artifact has four cases, 72 layouts, 28 link-opening observations, four script-free fallbacks and 44 decoded PNGs. The retained Composer artifact independently has 52/24/8/8 observations and 34 decoded PNGs. Menu HTTP CI observed file 441 checks in the browser job; MySQL 506 checks in PHP 8.2 and 506 checks in PHP 8.3; local combined 945 is supplementary. [Exact release and evidence](releases/0.8.11-menus.md). Actual-host #12/#13 and production email remain unverified; payments stay sandbox-only.

### Historical baseline — Build 20261020.1

Build 20261020.1's frozen local source passed `bash scripts/test.sh` on PHP **8.3.6**, including JavaScript/Python syntax, foreign-owner installation, persistent OPcache, security and runtime-only package gates, **139 grouped-button assertions** and **41 campaign assertions**. All **eight** `bash scripts/test_http.sh` suites passed, including **54 localhost SMTP/HTTP campaign assertions**. Independent browser acceptance exercised the actual grouped-button package at root and nested paths, including desktop/tablet/mobile geometry, Save/reload, retained Preview, Publish/history, real button destinations and zero broken image responses or JavaScript errors. The initial local HTTP dependency failure and its environment-only correction are recorded separately; the unchanged complete gate then passed.

That earlier build's seven GitHub jobs passed on PHP **8.2.34/8.3.35** and MySQL **8.0.46**, with desktop/mobile browser acceptance and gated packaging. Each checkout used test merge `5ccb46beadabe558b5bd4ccb907a8e6185532615`, whose complete tree equals source `9fcd355c6f7207287ab42babe04c80b3f3968a62` and actual PR #16 merge `0c4d6be5516c9c777c31524a33cd30c4d337f1d7`. Its saved-download readback matched that historical gated artifact. See [the Build 20261020.1 release record](releases/0.8.1-group-buttons-campaign-delivery.md).

### Deployment and service limits

The affected customer host has not been accessed; its actual served build and behavior remain unverified, and actual-host issues **#12/#13** remain open. A GitHub commit, passing workflow or delivered ZIP does not by itself establish what the host serves. If the site changes without a manual upload, record the exact page URL and the uploaded/running build from **System → Update Status** before attributing the change to a hosting connection or cache. No automatic hosting connection has been verified. Loopback SMTP capture does not establish production email delivery. Payments remain sandbox-only and no 0.9.0 work is included.

# Update and verify Velvet Vixxen — 0.8.10 / Build 20261020.11

Version **0.8.10**, Build **20261020.11**, makes the public Hero and Visual Footer use the same proportional canvas as Visual Composer. It also enlarges the bottom-footer text, reduces its empty spacing, adds a blue Cajun Web Pro link that opens a new tab, and keeps that footer above a visible audio player. Existing scene coordinates and device layouts need no migration or resave. The update preserves editorial history/restore, metadata and access, private saved-owner previews, SQL marketing persistence, publication windows, saved audiences, Media Center, add-on recovery, owner Email & SMTP, campaigns and organized-layout fixes. This increment adds no database migration or storage cutover. The unchanged unfinished-installer repair retains its separate **0.8.1 / Build 20261020.3** identity.

**Verified release:** 0.8.10 / Build 20261020.11 passed all seven required CI jobs and 66 configured steps, was merged through PR #26 at `a98847d032a50eabfbd117ade9cdce63de5c25c3` on `2026-10-21T00:23:10Z`, and its saved customer downloads passed literal read-back at `2026-10-21T00:25:31.617514+00:00`. [The release record](releases/0.8.10-composer-geometry.md), [CI record](releases/0.8.10-composer-geometry-ci.json) and [delivery record](releases/0.8.10-composer-geometry-delivery.json) bind the exact source, checkout, merge and package bytes. These instructions apply to the delivered complete Upgrade; the affected customer host still needs its own installation and behavior checks. The separate 0.8.11 Menus candidate is in progress and not delivered.

Rejected Build **20261019.5** reintroduced 25 public route folders and 46 forwarding PHP files at the website root. Build **20261019.6** supplied the approved restoration and safe owner repair. That repair remains included in this release; its historical CI/delivery records remain available.

## Existing 0.8.x installation

Keep a recoverable backup of the current site files and active database before applying an update. Preserve the existing `config`, `storage` and `uploads` directories, installation identity and owner account. Use the complete existing-site Upgrade; the Fresh Install package and unfinished-installer repair serve different installation states.

1. Extract the contents of the complete **Nikki-Wilder-Velvet-Vixxen-0.8.10-Build-20261020.11-Upgrade-from-0.8.x.zip** into the existing active website folder. Replace matching application files. The archive has no enclosing product folder; an installation under a subfolder must receive the files inside that same subfolder.
2. Sign in with the existing owner account and open **`admin/repair.php`** under this installation's URL. This entry remains usable when an older update page or PHP classes are cached. **System → Update Status** also opens this page.
3. Require **Update files verified**, **Uploaded package: v0.8.10 · Build 20261020.11**, and **Files needing replacement: 0**. If files are missing or different, complete extraction of the same full Upgrade and reload. The page verifies the actual files against the package inventory; the archive filename alone does not establish what is installed.
4. Click **Refresh application PHP**. After the page reloads, require **PHP running now: 20261020.11** and repeat the file checks above. If PHP caching is disabled, the page reports that condition after file verification; the running build must still match. Reload Composer and the public page so their HTML and versioned CSS/JavaScript requests use asset revision **0.8.10-20261020.11**. If an older page remains, hard-reload it or check a fresh browser window; review this site's hosting/CDN cache only if it still serves old responses after the file and PHP checks pass.
5. If the page detects forwarding files or empty route folders left by rejected Build .5, click **Restore organized folders** after file/build verification. The repair retires only the exact 46 known forwarding files and removes their 25 directories only when empty. Review its result and any custom or modified items preserved. If the page already reports that no retired compatibility files or folders remain, no folder-repair action is needed.
6. Complete **Verify Composer and public scene scale** and **Verify the readable, compact footer** below on the homepage and a common public page such as **About Me**.
7. Complete the retained grouped-button checks below, including a real image upload, the existing standalone **Add Button** action, retained **Full Page Preview**, and deliberate **Publish**.
8. Open **Media Center**, edit and save a gallery caption/tag and its order, then reload and confirm exact retained photographs. Check playlist order and dates. Follow [the Media Center guide](MEDIA_CENTER.md) for stale-form recovery and the owner action or hosting scheduler that runs due publication.
9. Open **Add-ons** and review any recorded recovery hold. Follow [the Add-on recovery guide](ADDON_RECOVERY.md); an uncertain database outcome requires review, and schema rollback/purge cannot restore deleted data without a database backup.
10. Open **System → Email & SMTP** and verify the owner settings using the steps below. Then check the campaign controls if you use newsletter campaigns.
11. If an installed MySQL site still has pending marketing migrations, open **System → Database Updates**, review/apply them, then follow the separate **Marketing Storage** move below if you want to move the marketing records. Applying the tables alone leaves the marketing file active. This 0.8.10 increment adds no migration.
12. Use the editorial metadata checks below to verify a saved post and landing page, including their public and **Preview Saved** views. Existing private history and restore remain available as described in the [Editorial history guide](EDITORIAL_HISTORY.md).

The Upgrade excludes `config`, `storage`, `uploads` and `install`. It does not reinstall the site, create a new owner, replace keys, run SQL or replace saved content. A ZIP overlay alone cannot remove folders from an earlier extraction; an installation still carrying the rejected .5 folders needs the verified owner repair action.

The repair preserves the exact old forwarding bytes as base64 JSON under protected private repair storage. It does not leave executable PHP backups. Unknown or changed files, symbolic links and custom subfolders are preserved in place. A folder containing one will remain and be listed. Failed filesystem operations are reported and can be retried; no broad recursive cleanup or world-writable permissions are used.

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

The delivered **0.8.10 / Build 20261020.11** has verified source/checkout/merge equality, all seven successful CI jobs and 66 required steps, actual runtime/browser artifact comparisons and literal saved-download read-back at `2026-10-21T00:25:31.617514+00:00`. The focused browser artifact contains 52 scene comparisons, 24 footer layouts, eight real new-tab clicks, eight empty-catalogue observations and 34 decoded PNGs. Its [release record](releases/0.8.10-composer-geometry.md) links the exact [CI](releases/0.8.10-composer-geometry-ci.json) and [delivery](releases/0.8.10-composer-geometry-delivery.json) evidence. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only. This evidence does not certify the separate 0.8.11 Menus candidate, which remains in progress and not delivered.

### Historical baseline — Build 20261020.1

Build 20261020.1's frozen local source passed `bash scripts/test.sh` on PHP **8.3.6**, including JavaScript/Python syntax, foreign-owner installation, persistent OPcache, security and runtime-only package gates, **139 grouped-button assertions** and **41 campaign assertions**. All **eight** `bash scripts/test_http.sh` suites passed, including **54 localhost SMTP/HTTP campaign assertions**. Independent browser acceptance exercised the actual grouped-button package at root and nested paths, including desktop/tablet/mobile geometry, Save/reload, retained Preview, Publish/history, real button destinations and zero broken image responses or JavaScript errors. The initial local HTTP dependency failure and its environment-only correction are recorded separately; the unchanged complete gate then passed.

That earlier build's seven GitHub jobs passed on PHP **8.2.34/8.3.35** and MySQL **8.0.46**, with desktop/mobile browser acceptance and gated packaging. Each checkout used test merge `5ccb46beadabe558b5bd4ccb907a8e6185532615`, whose complete tree equals source `9fcd355c6f7207287ab42babe04c80b3f3968a62` and actual PR #16 merge `0c4d6be5516c9c777c31524a33cd30c4d337f1d7`. Its saved-download readback matched that historical gated artifact. See [the Build 20261020.1 release record](releases/0.8.1-group-buttons-campaign-delivery.md).

### Deployment and service limits

The affected customer host has not been accessed; its actual served build and behavior remain unverified, and actual-host issues **#12/#13** remain open. A GitHub commit, passing workflow or delivered ZIP does not by itself establish what the host serves. If the site changes without a manual upload, record the exact page URL and the uploaded/running build from **System → Update Status** before attributing the change to a hosting connection or cache. No automatic hosting connection has been verified. Loopback SMTP capture does not establish production email delivery. Payments remain sandbox-only and no 0.9.0 work is included.

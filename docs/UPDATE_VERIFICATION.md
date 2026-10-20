# Update and verify Velvet Vixxen — 0.8.6 / Build 20261020.7

Version **0.8.6**, Build **20261020.7**, adds SQL marketing persistence and an explicitly reviewed owner move. It includes the previously released publication editors, saved audiences, Media Center, add-on recovery, owner Email & SMTP, Composer, campaigns and organized-layout fixes. The unchanged unfinished-installer repair retains its separate **0.8.1 / Build 20261020.3** identity.

**Current evidence:** 0.8.6 local acceptance passed; final GitHub CI, merge and saved-download verification are pending. [The release record](releases/0.8.6-marketing-sql.md) records the current state. Earlier release evidence does not certify this changed tree. The affected customer host has not been accessed or verified.

Rejected Build **20261019.5** reintroduced 25 public route folders and 46 forwarding PHP files at the website root. Build **20261019.6** supplied the approved restoration and safe owner repair. That repair remains part of 0.8.6; its historical CI/delivery records remain available.

## Existing 0.8.x installation

1. Extract the contents of the complete **Nikki-Wilder-Velvet-Vixxen-0.8.6-Build-20261020.7-Upgrade-from-0.8.x.zip** into the existing active website folder. Replace matching application files. The archive has no enclosing product folder; an installation under a subfolder must receive the files inside that same subfolder.
2. Sign in with the existing owner account and open **`admin/repair.php`** under this installation's URL. This entry remains usable when an older update page or PHP classes are cached. **System → Update Status** also opens this page.
3. Require **Update files verified**, **Uploaded package: v0.8.6 · Build 20261020.7**, and **Files needing replacement: 0**. If files are missing or different, complete extraction of the same full Upgrade and reload. The page verifies the actual files against the package inventory; the archive filename alone does not establish what is installed.
4. Click **Refresh application PHP**. After the page reloads, require **PHP running now: 20261020.7** and repeat the file checks above. If PHP caching is disabled, the page reports that condition after file verification; the running build must still match.
5. If the page detects forwarding files or empty route folders left by rejected Build .5, click **Restore organized folders** after file/build verification. The repair retires only the exact 46 known forwarding files and removes their 25 directories only when empty. Review its result and any custom or modified items preserved. If the page already reports that no retired compatibility files or folders remain, no folder-repair action is needed.
6. Open Visual Composer and complete the grouped-button checks below, including a real image upload, the existing standalone **Add Button** action, retained **Full Page Preview**, and **Publish**.
7. Open **Media Center**, edit and save a gallery caption/tag and its order, then reload and confirm exact retained photographs. Check playlist order and dates. Follow [the Media Center guide](MEDIA_CENTER.md) for stale-form recovery and the owner action or hosting scheduler that runs due publication.
8. Open **Add-ons** and review any recorded recovery hold. Follow [the Add-on recovery guide](ADDON_RECOVERY.md); an uncertain database outcome requires review, and schema rollback/purge cannot restore deleted data without a database backup.
9. Open **System → Email & SMTP** and verify the owner settings using the steps below. Then check the campaign controls if you use newsletter campaigns.
10. For an installed MySQL site, open **System → Database Updates**, review/apply the pending marketing migration, then follow the separate **Marketing Storage** move below. Applying the tables alone leaves the marketing file active.

The Upgrade excludes `config`, `storage`, `uploads` and `install`. It does not reinstall the site, create a new owner, replace keys, run SQL or replace saved content. A ZIP overlay alone cannot remove folders from an earlier extraction; an installation still carrying the rejected .5 folders needs the verified owner repair action.

The repair preserves the exact old forwarding bytes as base64 JSON under protected private repair storage. It does not leave executable PHP backups. Unknown or changed files, symbolic links and custom subfolders are preserved in place. A folder containing one will remain and be listed. Failed filesystem operations are reported and can be retried; no broad recursive cleanup or world-writable permissions are used.

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

Overlapping banners retain saved order: the first is **Active · shown now**; others are **Active · waiting behind the shown banner**. Editing keeps this order. Disable the shown banner to check the next active one, using a browser that has not dismissed the promotion.

### Withdraw, restore and remove

**Move to draft** hides a post; **Publish** honors its saved date, so a future post remains scheduled. **Disable** withdraws a banner or landing page; **Enable** honors its saved window, including an end that has passed. These controls retain content, links and dates.

Use **Delete** on a disposable verification post and confirm that its row and **View** address are gone while other posts remain. Banners and landing pages have **Disable** as their visible withdrawal control.

### Check stale edits and older dates

Open an item in two tabs. Save in the first, then try saving or changing visibility from the older tab. It must refuse the stale request and preserve the newer version. The rejected editor keeps your attempted text; reload the current item before reapplying it. A site timezone change also requires reloading. Edits to other items do not invalidate the form.

Invalid nonempty saved dates hold the item out of public view as **Needs review**. **Edit** shows the original value for repair. Enter a real local value in `YYYY-MM-DDTHH:MM` format, or deliberately clear it using the blank-date behavior above. Impossible dates, relative phrases and skipped daylight-saving times are refused. **Move to draft** or **Disable** remains available; publishing or enabling requires repaired dates.

Valid older dates retain their meaning: absolute timestamps keep their instant, local dates use the site timezone, and date-only values mean local midnight. An unchanged valid date retains its saved instant, including during a repeated daylight-saving hour.

See [Publication windows and saved promotion editors](PUBLICATION_WINDOWS.md) for the complete owner workflow.

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

Current **0.8.6 / Build 20261020.7** local acceptance passed; final GitHub gates, merge and saved-download verification are pending. Exact results will be recorded in [the release record](releases/0.8.6-marketing-sql.md) after execution.

### Historical baseline — Build 20261020.1

Build 20261020.1's frozen local source passed `bash scripts/test.sh` on PHP **8.3.6**, including JavaScript/Python syntax, foreign-owner installation, persistent OPcache, security and runtime-only package gates, **139 grouped-button assertions** and **41 campaign assertions**. All **eight** `bash scripts/test_http.sh` suites passed, including **54 localhost SMTP/HTTP campaign assertions**. Independent browser acceptance exercised the actual grouped-button package at root and nested paths, including desktop/tablet/mobile geometry, Save/reload, retained Preview, Publish/history, real button destinations and zero broken image responses or JavaScript errors. The initial local HTTP dependency failure and its environment-only correction are recorded separately; the unchanged complete gate then passed.

That earlier build's seven GitHub jobs passed on PHP **8.2.34/8.3.35** and MySQL **8.0.46**, with desktop/mobile browser acceptance and gated packaging. Each checkout used test merge `5ccb46beadabe558b5bd4ccb907a8e6185532615`, whose complete tree equals source `9fcd355c6f7207287ab42babe04c80b3f3968a62` and actual PR #16 merge `0c4d6be5516c9c777c31524a33cd30c4d337f1d7`. Its saved-download readback matched that historical gated artifact. See [the Build 20261020.1 release record](releases/0.8.1-group-buttons-campaign-delivery.md).

### Deployment and service limits

The affected customer host has not been accessed; its actual served build and behavior remain unverified. Loopback SMTP capture does not establish production email delivery. Payments remain sandbox-only and no 0.9.0 work is included.

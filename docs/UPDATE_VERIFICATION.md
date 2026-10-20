# Update and verify Velvet Vixxen — 0.8.3 / Build 20261020.4

Version **0.8.3**, Build **20261020.4**, adds guarded add-on-owned migration recovery, aligned package/schema rollback and durable holds across interrupted activation. It includes the released 0.8.2 Media Center, owner Email & SMTP, Composer, campaigns and organized-layout fixes. The unchanged unfinished-installer repair retains its separate **0.8.1 / Build 20261020.3** identity.

**Current evidence:** local 0.8.3 source and packaged MySQL/HTTP/browser acceptance passed; GitHub CI, merge and saved-download verification are pending. Use [the numbered release record](releases/0.8.3-addon-recovery.md) for the actual current state. Historical 0.8.2 CI does not certify this changed runtime. The affected customer host remains unverified.

Rejected Build **20261019.5** reintroduced 25 public route folders and 46 forwarding PHP files at the website root. Build **20261019.6** supplied the approved restoration and safe owner repair. That repair remains part of 0.8.3; its historical CI/delivery records remain available.

## Existing 0.8.x installation

1. Extract the contents of the complete **Nikki-Wilder-Velvet-Vixxen-0.8.3-Build-20261020.4-Upgrade-from-0.8.x.zip** into the existing active website folder. Replace matching application files. The archive has no enclosing product folder; an installation under a subfolder must receive the files inside that same subfolder.
2. Sign in with the existing owner account and open **`admin/repair.php`** under this installation's URL. This entry remains usable when an older update page or PHP classes are cached. **System → Update Status** also opens this page.
3. Require **Update files verified**, **Uploaded package: v0.8.3 · Build 20261020.4**, and **Files needing replacement: 0**. If files are missing or different, complete extraction of the same full Upgrade and reload. The page verifies the actual files against the package inventory; the archive filename alone does not establish what is installed.
4. Click **Refresh application PHP**. After the page reloads, require **PHP running now: 20261020.4** and repeat the file checks above. If PHP caching is disabled, the page reports that condition after file verification; the running build must still match.
5. If the page detects forwarding files or empty route folders left by rejected Build .5, click **Restore organized folders** after file/build verification. The repair retires only the exact 46 known forwarding files and removes their 25 directories only when empty. Review its result and any custom or modified items preserved. If the page already reports that no retired compatibility files or folders remain, no folder-repair action is needed.
6. Open Visual Composer and complete the grouped-button checks below, including a real image upload, the existing standalone **Add Button** action, retained **Full Page Preview**, and **Publish**.
7. Open **Media Center**, edit and save a gallery caption/tag and its order, then reload and confirm exact retained photographs. Check playlist order and dates. Follow [the Media Center guide](MEDIA_CENTER.md) for stale-form recovery and the owner action or hosting scheduler that runs due publication.
8. Open **Add-ons** and review any recorded recovery hold. Follow [the Add-on recovery guide](ADDON_RECOVERY.md); an uncertain database outcome requires review, and schema rollback/purge cannot restore deleted data without a database backup.
9. Open **System → Email & SMTP** and verify the owner settings using the steps below. Then check the campaign controls if you use newsletter campaigns.

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

## Campaign settings and bounded delivery

Open **Marketing → Campaigns** as the owner. The **Campaign delivery** status describes the saved shared mail transport. Configure that connection in **System → Email & SMTP**; the Upgrade preserves the private installation configuration. If the status reports disabled or incomplete mail configuration, save and verify the connection there before running delivery. Campaign switches remain separate from SMTP connection settings.

1. Set **Public newsletter signup and confirmation** and **Campaign email delivery** independently, then click **Save Marketing Settings**. Turning off signup hides signup/confirmation routes while existing unsubscribe links remain available. Turning off campaign delivery holds campaign work.
2. Under **Email Campaign**, enter the subject and message, optional preheader and optional schedule. The form displays the schedule timezone. Leave the schedule blank for work that may run now. Check **Queue for confirmed subscribers** when ready and click **Save Campaign**, or save the draft first and later use **Queue Recipients** on its saved card. Queueing freezes the message text; create a new campaign for different text. Existing legacy prepared items require the explicit **Queue Recipients** action before delivery.
3. When the transport is ready, choose **Messages per batch** from 1 to 10 and click **Run Due Campaign Batch**. Only explicitly queued, due campaigns with currently confirmed and consented recipients are eligible. Every campaign message includes an unsubscribe link. The page reports queued, processing, accepted, failed, unknown and suppressed counts; accepted means the transport accepted the message, not that an inbox received it.
4. Use **Pause Campaign** to stop waiting work and **Resume Campaign** when ready. Pause remains usable from another owner tab while a batch waits on SMTP. Consent, suppression and campaign state are checked again immediately before transmission. A message already transmitting cannot be recalled.
5. Inspect **Delivery problems** before using **Retry Failed Deliveries**. Only definite failures can be explicitly retried, with cooldowns and a three-attempt limit. Accepted and unknown outcomes are not retried; an unknown acknowledgement may mean the transport already accepted the message. Repeated queue/run actions do not duplicate an existing delivery for the same campaign and address.

For scheduled hosting execution, run `php app/Console/run_marketing.php --limit=10` from this installation's directory, or use its absolute script path in the hosting scheduler. The CLI accepts 1–25 messages per invocation and uses the same schedule, consent, pause, locking and retry checks. It must be invoked by the scheduler for future work to run; saving a schedule does not create a hosting cron job.

## Approved package layout

| Variant | Allowed root directories | Root files |
| --- | --- | --- |
| Fresh installation | `addons`, `admin`, `app`, `config`, `database`, `images`, `includes`, `install`, `public`, `storage`, `templates`, `uploads`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |
| Existing-site Upgrade | `addons`, `admin`, `app`, `database`, `images`, `includes`, `public`, `templates`, `vendor` | `.htaccess`, `VERSION`, `index.php`, `route.php` |

Public pages and APIs are implemented under `public/routes`. Generated internal URLs use the real `route.php` dispatcher, so actions can execute on root or subfolder installations without relying on rewrites for missing PHP files. Existing pretty paths remain aliases through the real `.htaccess` rules. Old external pretty links need working web-server rewrite handling; the package does not pretend a removed PHP file is still physically present.

## Evidence and limits

The preserved installed-update regression reconstructs the exact rejected Build .5 ZIP from its pinned source/hash, keeps the same browser and PHP process through extraction, proves that extraction alone leaves the unwanted folders, refreshes through the owner entry and performs real cleanup and application actions. Separate package tests reject the actual .5 root maps and check the exact approved root names.

Current **0.8.3 / Build 20261020.4** source, database, HTTP, actual packaged browser, security, ownership, persistent OPcache and package gates are being run against the changed tree. Final evidence will be recorded in [the 0.8.3 release record](releases/0.8.3-addon-recovery.md) after it completes. Do not apply historical 0.8.1 CI results to this new release.

### Historical baseline — Build 20261020.1

Build 20261020.1's frozen local source passed `bash scripts/test.sh` on PHP **8.3.6**, including JavaScript/Python syntax, foreign-owner installation, persistent OPcache, security and runtime-only package gates, **139 grouped-button assertions** and **41 campaign assertions**. All **eight** `bash scripts/test_http.sh` suites passed, including **54 localhost SMTP/HTTP campaign assertions**. Independent browser acceptance exercised the actual grouped-button package at root and nested paths, including desktop/tablet/mobile geometry, Save/reload, retained Preview, Publish/history, real button destinations and zero broken image responses or JavaScript errors. The initial local HTTP dependency failure and its environment-only correction are recorded separately; the unchanged complete gate then passed.

That earlier build's seven GitHub jobs passed on PHP **8.2.34/8.3.35** and MySQL **8.0.46**, with desktop/mobile browser acceptance and gated packaging. Each checkout used test merge `5ccb46beadabe558b5bd4ccb907a8e6185532615`, whose complete tree equals source `9fcd355c6f7207287ab42babe04c80b3f3968a62` and actual PR #16 merge `0c4d6be5516c9c777c31524a33cd30c4d337f1d7`. Its saved-download readback matched that historical gated artifact. See [the Build 20261020.1 release record](releases/0.8.1-group-buttons-campaign-delivery.md).

### Deployment and service limits

The affected customer host has not been accessed; its actual served build and behavior remain unverified. Loopback SMTP capture does not establish production email delivery. Payments remain sandbox-only and no 0.9.0 work is included.

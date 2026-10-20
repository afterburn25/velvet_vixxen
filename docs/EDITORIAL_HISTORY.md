# Editorial History and Restore

## Release status

**0.8.9 / Build 20261020.10 is verified, merged and delivered.** [PR #25](https://github.com/afterburn25/velvet_vixxen/pull/25) merged after all seven jobs and 65 configured required steps passed in [CI run 39510626159](https://github.com/afterburn25/velvet_vixxen/actions/runs/39510626159). Saved package bytes were retrieved and compared literally at `2026-10-20T23:13:07.846261+00:00`. This guide describes the delivered owner workflow; [the final release record](releases/0.8.9-editorial-history.md) contains exact source, CI, merge and download evidence.

## Open an item's history

Open **Marketing & Publishing → Posts**, **Banners**, or **Campaigns → Campaign Landing Page** as the active owner. Each saved item has a **History** link in its editor and list. Save any current editor changes before leaving the editor; History reviews saved content and does not include unsaved form text.

Choose **History**, then **Review version** beside an earlier version. The review shows the selected saved content and settings before you decide whether to restore it. Opening History or reviewing a version does not change the current publication, publish a draft, send email or record a marketing visit.

History is available only to the current active owner. It is not a public preview or a share link. The existing **Preview Saved** action continues to preview the currently saved item separately.

## What is retained

Each existing item keeps its **20 most recent earlier versions**, with the newest first. The currently saved item is separate from that list. A genuine change to its editorial fields or visibility preserves the state being replaced. Saving unchanged editorial values does not add a duplicate history entry.

History begins when an existing item is changed after this upgrade. The first such change preserves the item as it was immediately before that change. Creating a new item has no earlier state to preserve. The feature cannot reconstruct edits made before history was installed.

| Item | Content available for restoration |
| --- | --- |
| Post | Title, category, excerpt, body, publication date, access policy, public byline, tags, featured-image reference and description, search fields and ordered related-post selections. |
| Banner | Text, destination, start/end dates and access policy. |
| Campaign landing page | Title, eyebrow, excerpt, body, button label/destination, start/end dates, access policy, featured-image reference and description, and search fields. |

The capture time records when the earlier state was preserved. Each entry also retains the site timezone in effect at capture, so older local dates can be interpreted consistently if the site timezone later changes. Dates that already contain an explicit offset keep their original instant.

Image and related-post selections remain references to existing saved items. History does not copy old image files, recreate deleted media or restore a related post's separate content. Current image safety and publication-access rules still apply when references are displayed or restored.

## Restore an earlier version

1. Save any current edits you want to retain, then open **History** for that same item.
2. Choose **Review version** and inspect the saved content, dates, access and referenced items.
3. For a post, choose **Restore as draft**. For a banner or landing page, choose **Restore disabled**.
4. Review the restored item in its editor. Check its dates, access and metadata, then use **Preview Saved** if needed.
5. Publish the post or enable the banner/page through the ordinary editor only when the restored content is ready.

A restore always leaves a post in **Draft**, or a banner/page **Disabled**, even if the selected earlier version was public. It can therefore take a currently visible item offline. Restoring does not automatically republish it or bypass its existing schedule and access rules.

The item keeps its current ID, permalink, position and creation identity. Unrelated current fields remain intact. The version being replaced is preserved in History when the restoration changes editorial content, subject to the same 20-version limit. This allows another reviewed restore while that version remains retained.

## If a version cannot be restored

Restoration validates the saved content and its references against the current site. An old featured image may have been removed or made private; a related post may have been deleted; a selected membership tier may be disabled or unavailable. Malformed historical values can also prevent restoration. A failed validation leaves the current content and its history unchanged.

The selected historical version is not silently rewritten to substitute a different image, tier or related post. Review the reported problem. Restore the required reference through its ordinary management tools when appropriate, or use the current editor to make a deliberate replacement. Then reopen History before attempting restoration again.

If another save, visibility change, restore or site-timezone change makes the review stale, the restore is refused. Reload the current item and review the intended version again. A version that has fallen outside the 20-entry retention limit is no longer available to restore.

If the history itself is damaged, ordinary editing, review or restoration may be held for investigation. Existing history is preserved rather than silently erased. **Move to draft** or **Disable** remains available to take an item offline without resetting its damaged history.

## Preservation and limits

File storage and active MySQL marketing storage use the same history and restoration rules. History is saved atomically with the publication in the existing backend; this increment introduces no database migration or automatic storage move.

Publication history contains only supported editorial fields. It does not copy account details, arbitrary stored fields, newsletter contacts, consent, suppressions, frozen messages, delivery queues or outcomes. Public publication readers do not return private history.

Deleting a post permanently removes that post and its attached history. To retain the content and its versions, move it to draft instead. **Deleted-item recovery, complete site backups, a content calendar and explicit post expiry are outside this increment.** Composer scene history remains a separate existing feature.

The related [metadata](EDITORIAL_METADATA.md), [access and saved-preview](EDITORIAL_ACCESS.md), [publication-window](PUBLICATION_WINDOWS.md) and [Marketing Storage](MARKETING_STORAGE.md) guides describe the rules that remain in effect. [Update verification](UPDATE_VERIFICATION.md) describes installation checks; source and package tests do not establish that the customer's actual host has been updated.
